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
        if ($enemy->is_boss) {
            return null;
        }

        $mark = $this->markForEnemy($enemy);
        if (!$mark || !$mark->is_active) {
            return null;
        }

        $currentQuantity = $this->ownedQuantity($character, $mark);
        if (!$this->rollPercent($this->effectiveDropRate($mark, $currentQuantity))) {
            return null;
        }

        return DB::transaction(function () use ($character, $mark) {
            $row = CharacterMonsterMark::where('character_id', $character->id)
                ->where('monster_mark_id', $mark->id)
                ->lockForUpdate()
                ->first();

            if (!$row) {
                $row = CharacterMonsterMark::create([
                    'character_id' => $character->id,
                    'monster_mark_id' => $mark->id,
                    'quantity' => 0,
                    'unlocked_level' => 0,
                ]);
            }

            $beforeLevel = $this->unlockedLevel((int) $row->quantity, $mark);
            $row->quantity++;
            $afterLevel = $this->unlockedLevel((int) $row->quantity, $mark);
            $row->unlocked_level = $afterLevel;
            $row->save();

            return [
                'monster_mark_id' => $mark->id,
                'name' => $mark->mark_name,
                'quantity' => 1,
                'total_quantity' => (int) $row->quantity,
                'before_level' => $beforeLevel,
                'unlocked_level' => $afterLevel,
                'level_up' => $afterLevel > $beforeLevel,
                'bonus_stat' => $mark->bonus_stat,
                'bonus_stat_label' => $this->statLabel((string) $mark->bonus_stat),
                'bonus_per_level' => $this->effectiveBonusPerLevel($mark),
                'total_bonus' => $this->totalBonus($afterLevel, $mark),
                'next_required' => $this->nextRequired((int) $row->quantity, $mark),
            ];
        });
    }

    public function collectionFor(Character $character): Collection
    {
        $owned = CharacterMonsterMark::where('character_id', $character->id)
            ->get()
            ->keyBy('monster_mark_id');
        $discoveredAreaIds = $this->discoveredAreaIds($character);

        return MonsterMark::query()
            ->select('monster_marks.*')
            ->with('enemy.area.city')
            ->join('enemies', 'enemies.id', '=', 'monster_marks.enemy_id')
            ->leftJoin('areas', 'areas.id', '=', 'enemies.area_id')
            ->leftJoin('cities', 'cities.id', '=', 'areas.city_id')
            ->where('monster_marks.is_active', true)
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
                        $isAreaDiscovered = (bool) ($first['is_area_discovered'] ?? false);

                        return [
                            'area' => $area,
                            'display_name' => $isAreaDiscovered ? ($area?->name ?? '不明な地域') : '？？？',
                            'is_area_discovered' => $isAreaDiscovered,
                            'entries' => $areaEntries->values(),
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

        $rows = CharacterMonsterMark::with('monsterMark')
            ->where('character_id', $character->id)
            ->get();

        foreach ($rows as $row) {
            $mark = $row->monsterMark;
            if (!$mark || !$mark->is_active || !array_key_exists((string) $mark->bonus_stat, $bonuses)) {
                continue;
            }

            $level = $this->unlockedLevel((int) $row->quantity, $mark);
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

    private function ownedQuantity(Character $character, MonsterMark $mark): int
    {
        return (int) CharacterMonsterMark::where('character_id', $character->id)
            ->where('monster_mark_id', $mark->id)
            ->value('quantity');
    }

    private function effectiveDropRate(MonsterMark $mark, int $currentQuantity): float
    {
        $dropRate = (float) $mark->drop_rate / self::BASE_DROP_RATE_DIVISOR;

        if ($currentQuantity >= self::DROP_RATE_REDUCTION_QUANTITY) {
            return $dropRate / self::COMPLETED_DROP_RATE_DIVISOR;
        }

        return $dropRate;
    }

    private function markForEnemy(Enemy $enemy): ?MonsterMark
    {
        return MonsterMark::firstOrCreate(
            ['enemy_id' => $enemy->id],
            [
                'mark_name' => $enemy->name . 'の印',
                'bonus_stat' => $this->bonusStat($enemy),
                'bonus_per_level' => $this->bonusPerLevel($enemy),
                'required_per_level' => 10,
                'max_level' => count(self::UNLOCK_THRESHOLDS),
                'drop_rate' => str_contains((string) ($enemy->role ?? ''), 'レア') ? 20.0 : 8.0,
                'is_active' => true,
            ]
        );
    }

    private function unlockedLevel(int $quantity, MonsterMark $mark): int
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
        $text = (string) ($enemy->type_name ?? '') . ' ' . (string) ($enemy->role ?? '') . ' ' . (string) ($enemy->name ?? '');

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
