<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterMonsterMark;
use App\Models\Enemy;
use App\Models\MonsterMark;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MonsterMarkService
{
    private const UNLOCK_THRESHOLDS = [1, 3, 7, 15];

    public function rollAndGrant(Character $character, Enemy $enemy): ?array
    {
        if ($enemy->is_boss) {
            return null;
        }

        $mark = $this->markForEnemy($enemy);
        if (!$mark || !$mark->is_active || !$this->rollPercent((float) $mark->drop_rate)) {
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

        return MonsterMark::with('enemy.area')
            ->where('is_active', true)
            ->orderBy('enemy_id')
            ->get()
            ->map(function (MonsterMark $mark) use ($owned) {
                $row = $owned->get($mark->id);
                $quantity = (int) ($row?->quantity ?? 0);
                $level = $this->unlockedLevel($quantity, $mark);

                return [
                    'mark' => $mark,
                    'enemy' => $mark->enemy,
                    'quantity' => $quantity,
                    'unlocked_level' => $level,
                    'next_required' => $this->nextRequired($quantity, $mark),
                    'max_level' => $this->maxUnlockLevel($mark),
                    'bonus_label' => $this->statLabel((string) $mark->bonus_stat),
                    'total_bonus' => $this->totalBonus($level, $mark),
                    'progress_percent' => $this->progressPercent($quantity, $mark),
                    'is_discovered' => $quantity > 0,
                    'is_complete' => $level >= $this->maxUnlockLevel($mark),
                ];
            });
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
