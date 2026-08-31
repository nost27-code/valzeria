<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterAreaProgress;
use App\Models\CharacterMonsterMark;
use App\Models\Enemy;
use App\Models\MonsterMark;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MonsterMarkService
{
    private const UNLOCK_THRESHOLDS = [1, 3, 7, 15];

    private const BASE_DROP_RATE_DIVISOR = 2.0;

    private const DROP_RATE_REDUCTION_QUANTITY = 15;

    private const COMPLETED_DROP_RATE_DIVISOR = 3.0;

    public function rollAndGrant(Character $character, Enemy $enemy): ?array
    {
        if (! $this->isEligibleEnemy($enemy)) {
            return null;
        }

        $mark = $this->markForEnemy($enemy);
        if (! $mark || ! $mark->is_active) {
            return null;
        }

        $equivalentMarkIds = $this->equivalentMarkIds($mark);
        $currentQuantity = $this->ownedQuantity($character, $equivalentMarkIds);
        if (! $this->rollPercent($this->effectiveDropRate($mark, $currentQuantity))) {
            return null;
        }

        return DB::transaction(function () use ($character, $mark, $equivalentMarkIds) {
            $rows = CharacterMonsterMark::where('character_id', $character->id)
                ->whereIn('monster_mark_id', $equivalentMarkIds->all())
                ->orderBy('monster_mark_id')
                ->lockForUpdate()
                ->get();
            $beforeQuantity = (int) $rows->sum('quantity');
            $row = $rows->first(
                fn (CharacterMonsterMark $ownedMark): bool => (int) $ownedMark->monster_mark_id === (int) $mark->id
            );

            if (! $row) {
                $row = CharacterMonsterMark::create([
                    'character_id' => $character->id,
                    'monster_mark_id' => $mark->id,
                    'quantity' => 0,
                    'unlocked_level' => 0,
                ]);
            }

            $beforeLevel = $this->unlockedLevel($beforeQuantity, $mark);
            $row->quantity++;
            $afterQuantity = $beforeQuantity + 1;
            $afterLevel = $this->unlockedLevel($afterQuantity, $mark);
            $row->unlocked_level = $afterLevel;
            $row->save();

            return [
                'monster_mark_id' => $mark->id,
                'name' => $mark->mark_name,
                'quantity' => 1,
                'total_quantity' => $afterQuantity,
                'before_level' => $beforeLevel,
                'unlocked_level' => $afterLevel,
                'level_up' => $afterLevel > $beforeLevel,
                'bonus_stat' => $mark->bonus_stat,
                'bonus_stat_label' => $this->statLabel((string) $mark->bonus_stat),
                'bonus_per_level' => $this->effectiveBonusPerLevel($mark),
                'total_bonus' => $this->totalBonus($afterLevel, $mark),
                'next_required' => $this->nextRequired($afterQuantity, $mark),
            ];
        });
    }

    public function collectionFor(Character $character): Collection
    {
        $owned = CharacterMonsterMark::where('character_id', $character->id)
            ->get()
            ->keyBy('monster_mark_id');
        $discoveredAreaIds = $this->discoveredAreaIds($character);

        $entries = MonsterMark::query()
            ->select('monster_marks.*')
            ->with('enemy.area.city')
            ->join('enemies', 'enemies.id', '=', 'monster_marks.enemy_id')
            ->leftJoin('areas', 'areas.id', '=', 'enemies.area_id')
            ->leftJoin('cities', 'cities.id', '=', 'areas.city_id')
            ->where('monster_marks.is_active', true)
            ->where('enemies.is_boss', false)
            ->orderByRaw('COALESCE(cities.sort_order, 999999)')
            ->orderByRaw('COALESCE(areas.sort_order, 999999)')
            ->orderByRaw('COALESCE(enemies.sort_order, 999999)')
            ->orderBy('monster_marks.enemy_id')
            ->get()
            ->map(function (MonsterMark $mark) use ($owned, $discoveredAreaIds) {
                $row = $owned->get($mark->id);
                $quantity = (int) ($row?->quantity ?? 0);
                $level = $this->unlockedLevel($quantity, $mark);
                $enemy = $mark->enemy;
                $area = $enemy?->area;
                $city = $area?->city;
                $isAreaDiscovered = $area && $discoveredAreaIds->contains((int) $area->id);

                return [
                    'mark' => $mark,
                    'enemy' => $enemy,
                    'area' => $area,
                    'city' => $city,
                    'quantity' => $quantity,
                    'unlocked_level' => $level,
                    'next_required' => $this->nextRequired($quantity, $mark),
                    'max_level' => $this->maxUnlockLevel($mark),
                    'bonus_label' => $this->statLabel((string) $mark->bonus_stat),
                    'total_bonus' => $this->totalBonus($level, $mark),
                    'progress_percent' => $this->progressPercent($quantity, $mark),
                    'is_discovered' => $quantity > 0,
                    'is_area_discovered' => (bool) $isAreaDiscovered,
                    'is_complete' => $level >= $this->maxUnlockLevel($mark),
                ];
            });

        return $this->deduplicateCollectionEntries($entries)
            ->filter(fn (array $entry): bool => (bool) ($entry['is_area_discovered'] ?? false)
                || (bool) ($entry['is_discovered'] ?? false))
            ->values();
    }

    public function groupedCollectionFor(Character $character, ?Collection $collection = null): Collection
    {
        $collection ??= $this->collectionFor($character);

        return $collection
            ->groupBy(fn (array $entry) => (int) ($entry['city']?->id ?? 0))
            ->map(function (Collection $cityEntries) {
                $city = $cityEntries->first()['city'] ?? null;
                $areas = $cityEntries
                    ->groupBy(fn (array $entry) => (int) ($entry['area']?->id ?? 0))
                    ->map(function (Collection $areaEntries) {
                        $first = $areaEntries->first();
                        $area = $first['area'] ?? null;
                        $isAreaDiscovered = $areaEntries->contains(fn (array $entry): bool => (bool) ($entry['is_area_discovered'] ?? false))
                            || $areaEntries->contains(fn (array $entry): bool => (bool) ($entry['is_discovered'] ?? false));
                        $entries = $areaEntries
                            ->map(function (array $entry) use ($isAreaDiscovered): array {
                                $entry['is_area_discovered'] = $isAreaDiscovered;

                                return $entry;
                            })
                            ->values();

                        return [
                            'area' => $area,
                            'display_name' => $isAreaDiscovered ? ($area?->name ?? '不明な地域') : '？？？',
                            'is_area_discovered' => $isAreaDiscovered,
                            'entries' => $entries,
                            'discovered_count' => $areaEntries->where('is_discovered', true)->count(),
                            'total_count' => $areaEntries->count(),
                            'total_quantity' => $areaEntries->sum('quantity'),
                        ];
                    })
                    ->values();

                return [
                    'city' => $city,
                    'city_name' => $city?->name ?? '不明な街',
                    'areas' => $areas,
                    'discovered_count' => $cityEntries->where('is_discovered', true)->count(),
                    'total_count' => $cityEntries->count(),
                    'total_quantity' => $cityEntries->sum('quantity'),
                ];
            })
            ->values();
    }

    public function permanentBonuses(Character $character): array
    {
        $bonuses = [
            'hp' => 0,
            'mp' => 0,
            'str' => 0,
            'def' => 0,
            'agi' => 0,
            'mag' => 0,
            'spr' => 0,
            'luk' => 0,
        ];

        $rows = CharacterMonsterMark::with('monsterMark.enemy.area')
            ->where('character_id', $character->id)
            ->get();

        $rowsByMark = $rows
            ->filter(fn (CharacterMonsterMark $row): bool => (bool) ($row->monsterMark?->is_active ?? false)
                && $this->isEligibleEnemy($row->monsterMark?->enemy))
            ->groupBy(fn (CharacterMonsterMark $row): string => $this->markSignature($row->monsterMark));

        foreach ($rowsByMark as $duplicateRows) {
            $mark = $duplicateRows
                ->sort(function (CharacterMonsterMark $a, CharacterMonsterMark $b): int {
                    return ((int) $a->monster_mark_id) <=> ((int) $b->monster_mark_id);
                })
                ->first()
                ?->monsterMark;
            if (! $mark || ! $mark->is_active || ! array_key_exists((string) $mark->bonus_stat, $bonuses)) {
                continue;
            }

            $quantity = $duplicateRows->sum(fn (CharacterMonsterMark $row): int => (int) $row->quantity);
            $level = $this->unlockedLevel($quantity, $mark);
            $bonuses[(string) $mark->bonus_stat] += $this->totalBonus($level, $mark);
        }

        return $bonuses;
    }

    public function summary(Character $character): array
    {
        $collection = $this->collectionFor($character);
        $bonuses = $this->permanentBonuses($character);

        return [
            'total_marks' => $collection->sum('quantity'),
            'discovered_count' => $collection->where('is_discovered', true)->count(),
            'total_count' => $collection->count(),
            'unlocked_levels' => $collection->sum('unlocked_level'),
            'bonuses' => $bonuses,
        ];
    }

    /**
     * 印図鑑と同じ「同一エリア・同名敵は1種類」の単位で、エリア別の収集状況を返す。
     *
     * @param  array<int, int>  $areaIds
     * @return Collection<int, array{
     *     area_id: int,
     *     total_count: int,
     *     discovered_count: int,
     *     full_count: int,
     *     is_complete: bool,
     *     is_full_complete: bool
     * }>
     */
    public function areaCompletionFor(Character $character, array $areaIds): Collection
    {
        $areaIds = collect($areaIds)
            ->map(static fn ($areaId): int => (int) $areaId)
            ->filter(static fn (int $areaId): bool => $areaId > 0)
            ->unique()
            ->values();

        if ($areaIds->isEmpty()) {
            return collect();
        }

        $markRows = DB::table('monster_marks')
            ->join('enemies', 'enemies.id', '=', 'monster_marks.enemy_id')
            ->where('monster_marks.is_active', true)
            ->where('enemies.is_boss', false)
            ->whereIn('enemies.area_id', $areaIds->all())
            ->where(function ($query): void {
                $query->whereNull('enemies.role')
                    ->orWhere('enemies.role', 'not like', '%ダンジョン主%');
            })
            ->orderBy('monster_marks.id')
            ->get([
                'monster_marks.id AS monster_mark_id',
                'enemies.area_id',
                'enemies.name AS enemy_name',
            ]);

        if ($markRows->isEmpty()) {
            return collect();
        }

        $ownedQuantities = CharacterMonsterMark::query()
            ->where('character_id', $character->id)
            ->whereIn('monster_mark_id', $markRows->pluck('monster_mark_id')->all())
            ->pluck('quantity', 'monster_mark_id');

        return $markRows
            ->groupBy(fn ($row): string => $this->signatureForAreaAndEnemy(
                (int) $row->area_id,
                (string) $row->enemy_name,
            ))
            ->map(function (Collection $equivalentMarks) use ($ownedQuantities): array {
                $first = $equivalentMarks->first();
                $quantity = (int) $equivalentMarks->sum(
                    fn ($mark): int => (int) ($ownedQuantities[(int) $mark->monster_mark_id] ?? 0)
                );

                return [
                    'area_id' => (int) $first->area_id,
                    'quantity' => $quantity,
                ];
            })
            ->values()
            ->groupBy('area_id')
            ->map(function (Collection $marks, $areaId): array {
                $totalCount = $marks->count();
                $discoveredCount = $marks->where('quantity', '>', 0)->count();
                $fullCount = $marks->where('quantity', '>=', self::DROP_RATE_REDUCTION_QUANTITY)->count();

                return [
                    'area_id' => (int) $areaId,
                    'total_count' => $totalCount,
                    'discovered_count' => $discoveredCount,
                    'full_count' => $fullCount,
                    'is_complete' => $totalCount > 0 && $discoveredCount === $totalCount,
                    'is_full_complete' => $totalCount > 0 && $fullCount === $totalCount,
                ];
            });
    }

    private function discoveredAreaIds(Character $character): Collection
    {
        return CharacterAreaProgress::query()
            ->where('character_id', $character->id)
            ->where(function ($query) {
                $query->where('is_unlocked', true)
                    ->orWhereIn('discovery_state', ['discovered', 'cleared'])
                    ->orWhere('boss_defeated', true)
                    ->orWhere('development_point', '>', 0)
                    ->orWhereNotNull('unlocked_at')
                    ->orWhereNotNull('discovered_at')
                    ->orWhereNotNull('cleared_at');
            })
            ->pluck('area_id')
            ->map(fn ($id) => (int) $id)
            ->values();
    }

    private function ownedQuantity(Character $character, Collection $markIds): int
    {
        return (int) CharacterMonsterMark::where('character_id', $character->id)
            ->whereIn('monster_mark_id', $markIds->all())
            ->sum('quantity');
    }

    private function equivalentMarkIds(MonsterMark $mark): Collection
    {
        $mark->loadMissing('enemy');
        $enemy = $mark->enemy;
        if (! $enemy) {
            return collect([(int) $mark->id]);
        }

        $ids = MonsterMark::query()
            ->where('is_active', true)
            ->whereHas('enemy', function ($query) use ($enemy) {
                $query->where('area_id', $enemy->area_id)
                    ->where('name', $enemy->name)
                    ->where('is_boss', false);
            })
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values();

        return $ids->isNotEmpty() ? $ids : collect([(int) $mark->id]);
    }

    private function deduplicateCollectionEntries(Collection $entries): Collection
    {
        return $entries
            ->groupBy(fn (array $entry): string => $this->entrySignature($entry))
            ->map(function (Collection $duplicates): array {
                if ($duplicates->count() === 1) {
                    return $duplicates->first();
                }

                $entry = $duplicates
                    ->sort(function (array $a, array $b): int {
                        $aHasQuantity = ((int) ($a['quantity'] ?? 0)) > 0 ? 0 : 1;
                        $bHasQuantity = ((int) ($b['quantity'] ?? 0)) > 0 ? 0 : 1;

                        return [$aHasQuantity, (int) ($a['mark']?->id ?? PHP_INT_MAX)]
                            <=> [$bHasQuantity, (int) ($b['mark']?->id ?? PHP_INT_MAX)];
                    })
                    ->first();

                $mark = $entry['mark'];
                $quantity = $duplicates->sum(fn (array $duplicate): int => (int) ($duplicate['quantity'] ?? 0));
                $level = $this->unlockedLevel($quantity, $mark);

                $entry['quantity'] = $quantity;
                $entry['unlocked_level'] = $level;
                $entry['next_required'] = $this->nextRequired($quantity, $mark);
                $entry['total_bonus'] = $this->totalBonus($level, $mark);
                $entry['progress_percent'] = $this->progressPercent($quantity, $mark);
                $entry['is_discovered'] = $quantity > 0;
                $entry['is_complete'] = $level >= $this->maxUnlockLevel($mark);

                return $entry;
            })
            ->values();
    }

    private function entrySignature(array $entry): string
    {
        $areaId = (int) ($entry['area']?->id ?? 0);
        $enemyName = trim((string) ($entry['enemy']?->name ?? $entry['mark']?->mark_name ?? ''));

        return $this->signatureForAreaAndEnemy($areaId, $enemyName);
    }

    private function markSignature(MonsterMark $mark): string
    {
        $enemy = $mark->enemy;
        $areaId = (int) ($enemy?->area_id ?? $enemy?->area?->id ?? 0);
        $enemyName = trim((string) ($enemy?->name ?? $mark->mark_name));

        return $this->signatureForAreaAndEnemy($areaId, $enemyName);
    }

    private function signatureForAreaAndEnemy(int $areaId, string $enemyName): string
    {
        return $areaId.':'.preg_replace('/の印$/u', '', trim($enemyName));
    }

    private function effectiveDropRate(MonsterMark $mark, int $currentQuantity): float
    {
        $dropRate = (float) $mark->drop_rate / self::BASE_DROP_RATE_DIVISOR;

        if ($currentQuantity >= self::DROP_RATE_REDUCTION_QUANTITY) {
            return $dropRate / self::COMPLETED_DROP_RATE_DIVISOR;
        }

        return $dropRate;
    }

    private function isEligibleEnemy(?Enemy $enemy): bool
    {
        if (! $enemy || (bool) $enemy->is_boss) {
            return false;
        }

        return ! str_contains((string) ($enemy->role ?? ''), 'ダンジョン主');
    }

    private function markForEnemy(Enemy $enemy): ?MonsterMark
    {
        $existing = $this->findActiveMarkForEnemy($enemy);
        if ($existing) {
            return $existing;
        }

        $sourceEnemy = $enemy;
        if ($enemy->getKey() !== null) {
            $persistedEnemy = Enemy::query()->find($enemy->getKey());
            if ($persistedEnemy
                && ((int) $persistedEnemy->area_id !== (int) $enemy->area_id
                    || trim((string) $persistedEnemy->name) !== trim((string) $enemy->name))) {
                $sourceEnemy = $persistedEnemy;
                $existing = $this->findActiveMarkForEnemy($sourceEnemy);
                if ($existing) {
                    return $existing;
                }
            }
        }

        return MonsterMark::firstOrCreate(
            ['enemy_id' => $sourceEnemy->id],
            [
                'mark_name' => $sourceEnemy->name.'の印',
                'bonus_stat' => $this->bonusStat($sourceEnemy),
                'bonus_per_level' => $this->bonusPerLevel($sourceEnemy),
                'required_per_level' => 10,
                'max_level' => count(self::UNLOCK_THRESHOLDS),
                'drop_rate' => str_contains((string) ($sourceEnemy->role ?? ''), 'レア') ? 20.0 : 8.0,
                'is_active' => true,
            ]
        );
    }

    private function findActiveMarkForEnemy(Enemy $enemy): ?MonsterMark
    {
        return MonsterMark::query()
            ->where('is_active', true)
            ->whereHas('enemy', function ($query) use ($enemy) {
                $query->where('area_id', $enemy->area_id)
                    ->where('name', $enemy->name)
                    ->where('is_boss', false);
            })
            ->orderBy('id')
            ->first();
    }

    public function unlockedLevel(int $quantity, MonsterMark $mark): int
    {
        $max = $this->maxUnlockLevel($mark);
        $level = 0;

        foreach (array_slice(self::UNLOCK_THRESHOLDS, 0, $max) as $threshold) {
            if ($quantity >= $threshold) {
                $level++;
            }
        }

        return $level;
    }

    private function nextRequired(int $quantity, MonsterMark $mark): ?int
    {
        $level = $this->unlockedLevel($quantity, $mark);
        if ($level >= $this->maxUnlockLevel($mark)) {
            return null;
        }

        $nextThreshold = self::UNLOCK_THRESHOLDS[$level] ?? null;
        if ($nextThreshold === null) {
            return null;
        }

        return max(0, $nextThreshold - $quantity);
    }

    private function progressPercent(int $quantity, MonsterMark $mark): int
    {
        $level = $this->unlockedLevel($quantity, $mark);
        if ($level >= $this->maxUnlockLevel($mark)) {
            return 100;
        }

        $previousThreshold = $level > 0 ? self::UNLOCK_THRESHOLDS[$level - 1] : 0;
        $nextThreshold = self::UNLOCK_THRESHOLDS[$level] ?? end(self::UNLOCK_THRESHOLDS);
        $span = max(1, $nextThreshold - $previousThreshold);

        return min(100, max(0, (int) floor(($quantity - $previousThreshold) / $span * 100)));
    }

    private function maxUnlockLevel(MonsterMark $mark): int
    {
        return min(count(self::UNLOCK_THRESHOLDS), max(0, (int) $mark->max_level));
    }

    private function totalBonus(int $level, MonsterMark $mark): int
    {
        if ($level <= 0) {
            return 0;
        }

        return $level * $this->effectiveBonusPerLevel($mark);
    }

    private function effectiveBonusPerLevel(MonsterMark $mark): int
    {
        $base = max(1, (int) $mark->bonus_per_level);

        return in_array((string) $mark->bonus_stat, ['hp', 'mp'], true)
            ? $base * 5
            : $base;
    }

    private function rollPercent(float $percent): bool
    {
        if ($percent <= 0) {
            return false;
        }

        return random_int(1, 10000) <= (int) round($percent * 100);
    }

    private function statLabel(string $stat): string
    {
        return [
            'hp' => 'HP',
            'mp' => 'SP',
            'str' => '攻撃',
            'def' => '防御',
            'agi' => '敏捷',
            'mag' => '魔力',
            'spr' => '精神',
            'luk' => '運',
        ][$stat] ?? '能力';
    }

    private function bonusStat(Enemy $enemy): string
    {
        $text = (string) ($enemy->type_name ?? '').' '.(string) ($enemy->role ?? '').' '.(string) ($enemy->name ?? '');

        if (str_contains($text, '耐久') || str_contains($text, '重装') || str_contains($text, '防御')) {
            return 'def';
        }
        if (str_contains($text, '高速') || str_contains($text, '俊敏') || str_contains($text, '飛行')) {
            return 'agi';
        }
        if (str_contains($text, '魔法') || str_contains($text, '魔導') || str_contains($text, '術')) {
            return 'mag';
        }
        if (str_contains($text, '聖') || str_contains($text, '祈') || str_contains($text, '精神')) {
            return 'spr';
        }
        if (str_contains($text, '幸運') || str_contains($text, '宝') || str_contains($text, '兎')) {
            return 'luk';
        }

        $stats = [
            'hp' => (int) (($enemy->max_hp ?? 0) / 8),
            'str' => (int) ($enemy->str ?? 0),
            'def' => (int) ($enemy->def ?? 0),
            'agi' => (int) ($enemy->agi ?? 0),
            'mag' => (int) ($enemy->mag ?? 0),
            'spr' => (int) ($enemy->spr ?? 0),
            'luk' => (int) ($enemy->luk ?? 0),
        ];

        arsort($stats);

        return array_key_first($stats) ?: 'str';
    }

    private function bonusPerLevel(Enemy $enemy): int
    {
        $stage = (int) ($enemy->area?->city_id ?? 1);

        return match (true) {
            $stage >= 7 => 3,
            $stage >= 4 => 2,
            default => 1,
        };
    }
}
