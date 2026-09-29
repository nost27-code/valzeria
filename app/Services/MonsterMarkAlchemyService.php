<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterMonsterMark;
use App\Models\MonsterMarkRefinement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;

class MonsterMarkAlchemyService
{
    public const MAX_TOTAL_POINTS = 20;

    public const MAX_POINTS_PER_STAT = 20;

    private const FIRST_TIER_END = 10;

    private const SECOND_TIER_END = 20;

    private const FIRST_TIER_COST = 20;

    private const SECOND_TIER_COST = 30;

    private const LATER_TIER_COST = 40;

    public function __construct(
        private MonsterMarkService $monsterMarkService,
        private BonusPointService $bonusPointService,
    ) {}

    public function isOpen(): bool
    {
        return CarbonImmutable::now()->gte(CarbonImmutable::parse(config('monster_mark_alchemy.opens_at')));
    }

    public function statOptions(): array
    {
        return collect($this->bonusPointService->statOptions())
            ->map(fn (array $option): array => [
                ...$option,
                'max_points' => self::MAX_POINTS_PER_STAT,
            ])
            ->all();
    }

    public function bonusPointsFor(Character $character): array
    {
        $points = array_fill_keys(array_keys($this->statOptions()), 0);
        if (! $this->schemaReady()) {
            return $points;
        }

        MonsterMarkRefinement::query()
            ->where('character_id', $character->id)
            ->selectRaw('stat, SUM(points) AS total_points')
            ->groupBy('stat')
            ->pluck('total_points', 'stat')
            ->each(function ($value, $stat) use (&$points): void {
                if (array_key_exists((string) $stat, $points)) {
                    $points[(string) $stat] = (int) $value;
                }
            });

        return $points;
    }

    public function bonusesFor(Character $character): array
    {
        $options = $this->statOptions();

        return collect($this->bonusPointsFor($character))
            ->mapWithKeys(fn (int $points, string $stat): array => [
                $stat => $points * (int) ($options[$stat]['gain'] ?? 0),
            ])
            ->all();
    }

    public function summary(Character $character): array
    {
        $points = $this->bonusPointsFor($character);
        $totalPoints = array_sum($points);
        $groups = $this->schemaReady()
            ? $this->surplusGroups($this->ownedRows($character))
            : collect();
        $surplusTotal = (int) $groups->sum('surplus_quantity');
        $nextCost = $this->nextCost($totalPoints);
        $displayGroups = $groups
            ->where('surplus_quantity', '>', 0)
            ->sortBy([
                ['surplus_quantity', 'desc'],
                ['mark_name', 'asc'],
            ])
            ->values();

        return [
            'protected_quantity' => $this->monsterMarkService->protectedQuantity(),
            'surplus_total' => $surplusTotal,
            'total_points' => $totalPoints,
            'max_total_points' => self::MAX_TOTAL_POINTS,
            'remaining_points' => max(0, self::MAX_TOTAL_POINTS - $totalPoints),
            'next_cost' => $nextCost,
            'can_refine' => $nextCost !== null && $surplusTotal >= $nextCost,
            'points_by_stat' => $points,
            'bonuses' => $this->bonusesFor($character),
            'surplus_groups' => $displayGroups->take(20)->map(fn (array $group): array => [
                'mark_name' => $group['mark_name'],
                'owned_quantity' => $group['owned_quantity'],
                'spent_quantity' => $group['spent_quantity'],
                'surplus_quantity' => $group['surplus_quantity'],
            ])->values(),
            'hidden_surplus_group_count' => max(0, $displayGroups->count() - 20),
            'rate_schedule' => [
                ['range' => '1〜10回目', 'cost' => self::FIRST_TIER_COST],
                ['range' => '11〜20回目', 'cost' => self::SECOND_TIER_COST],
                ['range' => '21回目以降', 'cost' => self::LATER_TIER_COST],
            ],
        ];
    }

    /**
     * 高頻度の戦闘結果画面向けに、錬成進捗だけを返す。
     */
    public function battleResultProgress(Character $character): ?array
    {
        if (! $this->isOpen() || ! $this->schemaReady()) {
            return null;
        }

        $totalPoints = (int) MonsterMarkRefinement::query()
            ->where('character_id', $character->id)
            ->sum('points');
        $surplusTotal = (int) $this->surplusGroups($this->ownedRows($character))
            ->sum('surplus_quantity');
        $nextCost = $this->nextCost($totalPoints);

        return [
            'surplus_total' => $surplusTotal,
            'total_points' => $totalPoints,
            'next_cost' => $nextCost,
            'remaining_to_next' => $nextCost === null ? null : max(0, $nextCost - $surplusTotal),
            'at_cap' => $nextCost === null,
        ];
    }

    public function refine(Character $character, string $stat, string $requestToken): array
    {
        if (! $this->isOpen()) {
            throw new RuntimeException('印錬成所はまだ開業していません。');
        }

        if (! $this->schemaReady()) {
            throw new RuntimeException('印錬成の準備が完了していません。');
        }

        $options = $this->statOptions();
        if (! array_key_exists($stat, $options)) {
            throw new InvalidArgumentException('錬成する能力が不正です。');
        }

        $result = DB::transaction(function () use ($character, $stat, $requestToken, $options): array {
            $lockedCharacter = Character::query()->whereKey($character->id)->lockForUpdate()->firstOrFail();
            $existing = MonsterMarkRefinement::query()
                ->where('character_id', $lockedCharacter->id)
                ->where('request_token', $requestToken)
                ->first();

            if ($existing) {
                return $this->resultFor($existing, $options, true);
            }

            $pointsByStat = MonsterMarkRefinement::query()
                ->where('character_id', $lockedCharacter->id)
                ->selectRaw('stat, SUM(points) AS total_points')
                ->groupBy('stat')
                ->pluck('total_points', 'stat');
            $totalPoints = (int) $pointsByStat->sum();
            $statPoints = (int) ($pointsByStat[$stat] ?? 0);
            $cost = $this->nextCost($totalPoints);

            if ($cost === null) {
                throw new RuntimeException('現在の印錬成上限に到達しています。');
            }
            if ($statPoints >= self::MAX_POINTS_PER_STAT) {
                throw new RuntimeException($options[$stat]['label'].'は現在の錬成上限に到達しています。');
            }

            $rows = CharacterMonsterMark::query()
                ->where('character_id', $lockedCharacter->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $rows->load('monsterMark.enemy');
            $groups = $this->surplusGroups($rows)
                ->where('surplus_quantity', '>', 0)
                ->sortBy([
                    ['surplus_quantity', 'desc'],
                    ['mark_name', 'asc'],
                ])
                ->values();

            if ((int) $groups->sum('surplus_quantity') < $cost) {
                throw new RuntimeException("余剰印が不足しています。次の錬成には{$cost}個必要です。");
            }

            $remaining = $cost;
            $consumed = [];
            foreach ($groups as $group) {
                if ($remaining <= 0) {
                    break;
                }

                $groupTake = min($remaining, (int) $group['surplus_quantity']);
                $groupRemaining = $groupTake;
                foreach ($group['rows'] as $row) {
                    if ($groupRemaining <= 0) {
                        break;
                    }

                    $rowAvailable = max(0, (int) $row->quantity - (int) ($row->spent_quantity ?? 0));
                    $take = min($groupRemaining, $rowAvailable);
                    if ($take <= 0) {
                        continue;
                    }

                    $row->spent_quantity = (int) ($row->spent_quantity ?? 0) + $take;
                    $row->save();
                    $consumed[] = [
                        'monster_mark_id' => (int) $row->monster_mark_id,
                        'mark_name' => (string) ($row->monsterMark?->mark_name ?? $group['mark_name']),
                        'quantity' => $take,
                    ];
                    $groupRemaining -= $take;
                    $remaining -= $take;
                }
            }

            if ($remaining !== 0) {
                throw new RuntimeException('余剰印の消費処理を完了できませんでした。');
            }

            $refinement = MonsterMarkRefinement::query()->create([
                'character_id' => $lockedCharacter->id,
                'request_token' => $requestToken,
                'stat' => $stat,
                'points' => 1,
                'mark_cost' => $cost,
                'consumed_marks' => $consumed,
            ]);

            $gain = (int) $options[$stat]['gain'];
            if ($stat === 'hp') {
                $lockedCharacter->current_hp = (int) $lockedCharacter->current_hp + $gain;
                $lockedCharacter->save();
            } elseif ($stat === 'mp') {
                $lockedCharacter->current_mp = (int) $lockedCharacter->current_mp + $gain;
                $lockedCharacter->save();
            }

            return $this->resultFor($refinement, $options, false);
        });

        CharacterStatusService::clearRequestCache((int) $character->id);

        return $result;
    }

    public function nextCost(int $totalPoints): ?int
    {
        if ($totalPoints >= self::MAX_TOTAL_POINTS) {
            return null;
        }
        if ($totalPoints < self::FIRST_TIER_END) {
            return self::FIRST_TIER_COST;
        }
        if ($totalPoints < self::SECOND_TIER_END) {
            return self::SECOND_TIER_COST;
        }

        return self::LATER_TIER_COST;
    }

    private function ownedRows(Character $character): Collection
    {
        return CharacterMonsterMark::query()
            ->where('character_id', $character->id)
            ->where('quantity', '>', 0)
            ->with('monsterMark.enemy')
            ->orderBy('id')
            ->get();
    }

    private function surplusGroups(Collection $rows): Collection
    {
        return $rows
            ->filter(fn (CharacterMonsterMark $row): bool => $this->monsterMarkService->isEligibleMark($row->monsterMark))
            ->groupBy(fn (CharacterMonsterMark $row): string => $this->monsterMarkService->equivalenceKey($row->monsterMark))
            ->map(function (Collection $groupRows): array {
                $ownedQuantity = (int) $groupRows->sum(fn (CharacterMonsterMark $row): int => (int) $row->quantity);
                $spentQuantity = (int) $groupRows->sum(fn (CharacterMonsterMark $row): int => min(
                    (int) $row->quantity,
                    max(0, (int) ($row->spent_quantity ?? 0)),
                ));
                $unspentQuantity = max(0, $ownedQuantity - $spentQuantity);

                return [
                    'mark_name' => (string) ($groupRows->first()?->monsterMark?->mark_name ?? '不明な印'),
                    'owned_quantity' => $ownedQuantity,
                    'spent_quantity' => $spentQuantity,
                    'surplus_quantity' => max(0, $unspentQuantity - $this->monsterMarkService->protectedQuantity()),
                    'rows' => $groupRows->sortBy('id')->values(),
                ];
            })
            ->values();
    }

    private function resultFor(MonsterMarkRefinement $refinement, array $options, bool $idempotent): array
    {
        $stat = (string) $refinement->stat;

        return [
            'stat' => $stat,
            'label' => (string) ($options[$stat]['label'] ?? '能力'),
            'gain' => (int) ($options[$stat]['gain'] ?? 0) * (int) $refinement->points,
            'spent_marks' => (int) $refinement->mark_cost,
            'total_points' => (int) MonsterMarkRefinement::query()
                ->where('character_id', $refinement->character_id)
                ->sum('points'),
            'idempotent' => $idempotent,
        ];
    }

    private function schemaReady(): bool
    {
        return Schema::hasTable('monster_mark_refinements')
            && Schema::hasTable('character_monster_marks')
            && Schema::hasColumn('character_monster_marks', 'spent_quantity');
    }
}
