<?php

namespace Database\Seeders;

use App\Models\Skill;
use App\Support\JobArtEffectCatalog;
use Illuminate\Database\Seeder;

class JobArtSeeder extends Seeder
{
    public function run(): void
    {
        $path = base_path('database/data/job_arts.json');
        if (!is_file($path)) {
            return;
        }

        $rows = json_decode((string) file_get_contents($path), true);
        if (!is_array($rows)) {
            return;
        }

        foreach ($rows as $row) {
            $jobId = (int) ($row['job_id'] ?? 0);
            $learnRank = (int) ($row['learn_rank'] ?? 0);
            $name = trim((string) ($row['name'] ?? ''));
            if ($jobId <= 0 || $learnRank <= 0 || $name === '') {
                continue;
            }

            $powerHint = $row['power_hint'] ?? 0;
            $power = $this->numericPower($powerHint);
            $template = (string) ($row['effect_template'] ?? '');

            Skill::updateOrCreate(
                [
                    'job_id' => $jobId,
                    'learn_rank' => $learnRank,
                    'name' => $name,
                    'skill_type' => 'job_art',
                ],
                [
                    'skill_type' => 'job_art',
                    'effect_template' => $template,
                    'art_category' => $row['art_category'] ?? null,
                    'limit_group' => strtoupper((string) ($row['limit_group'] ?? 'NONE')),
                    'element' => $row['element'] ?? null,
                    'power' => $power,
                    'duration_turns' => $this->nullableInt($row['duration_turns'] ?? null),
                    'sp_cost_fixed' => $this->fixedSpCostFor($row),
                    'sp_cost_rate' => (float) ($row['sp_cost_rate'] ?? 0),
                    'activation_rate' => (int) ($row['activation_rate'] ?? 0),
                    'trigger_rate' => (int) ($row['activation_rate'] ?? 0),
                    'art_cost' => (int) ($row['art_cost'] ?? 0),
                    'cooldown_turns' => (int) ($row['cooldown_turns'] ?? 0),
                    'max_uses_per_battle' => $this->nullableInt($row['max_uses_per_battle'] ?? null),
                    'inherit_on_master' => $this->boolValue($row['inherit_on_master'] ?? true),
                    'inherit_policy' => $row['inherit_policy'] ?? 'reduced',
                    'inherited_rate' => (float) ($row['inherited_rate'] ?? 1.0),
                    'pve_enabled' => $this->boolValue($row['pve_enabled'] ?? true),
                    'boss_enabled' => $this->boolValue($row['boss_enabled'] ?? true),
                    'champ_enabled' => $this->boolValue($row['champ_enabled'] ?? true),
                    'reward_scope' => $row['reward_scope'] ?? 'none',
                    'sort_order' => (int) ($row['sort_order'] ?? 0),
                    'memo' => $row['memo'] ?? null,
                    'description' => $row['memo'] ?? null,
                    'damage_type' => JobArtEffectCatalog::damageType($template),
                    'power_multiplier' => max(0, $power / 100),
                    'hit_count' => JobArtEffectCatalog::hitCount($template),
                    'heal_percent' => 0,
                    'mp_recover_percent' => (int) ($row['mp_recover_percent'] ?? 0),
                    'gold_bonus_percent' => $this->rewardBonusPercentFor($row, 'gold_bonus_percent', $template, $power),
                    'drop_bonus_percent' => $this->rewardBonusPercentFor($row, 'drop_bonus_percent', $template, $power),
                    'activation_phrase' => $this->nullableString($row['activation_phrase'] ?? null),
                    'activation_description' => $this->nullableString($row['activation_description'] ?? null),
                ]
            );
        }
    }

    private function numericPower(mixed $value): int
    {
        if (is_numeric($value)) {
            return max(0, (int) $value);
        }

        if (preg_match('/\d+/', (string) $value, $matches)) {
            return max(0, (int) $matches[0]);
        }

        return 100;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    private function nullableString(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }

    private function fixedSpCostFor(array $row): int
    {
        if (isset($row['sp_cost_fixed']) && $row['sp_cost_fixed'] !== '') {
            return max(0, (int) $row['sp_cost_fixed']);
        }

        $rank = (int) ($row['learn_rank'] ?? 1);
        $column = match (true) {
            $rank >= 9 => 9,
            $rank >= 5 => 5,
            default => 1,
        };

        $template = (string) ($row['effect_template'] ?? '');
        $category = (string) ($row['art_category'] ?? '');
        $limitGroup = strtoupper((string) ($row['limit_group'] ?? 'NONE'));

        $costs = match (true) {
            $limitGroup === 'TIME' || $template === 'TIME_CONTROL_CURRENT_ONLY' => [1 => 12, 5 => 32, 9 => 65],
            $limitGroup === 'REWARD' || str_starts_with($template, 'REWARD_') || in_array($template, ['PHYSICAL_DAMAGE_REWARD', 'MAGICAL_DAMAGE_REWARD'], true) => [1 => 12, 5 => 30, 9 => 65],
            $limitGroup === 'GUTS' || $template === 'GUTS' => [1 => 12, 5 => 30, 9 => 65],
            $limitGroup === 'HEAL' || in_array($template, ['HEAL', 'HEAL_CLEANSE'], true) => [1 => 10, 5 => 26, 9 => 60],
            $template === 'DRAIN' => [1 => 10, 5 => 28, 9 => 62],
            $template === 'MULTI_HIT' => [1 => 8, 5 => 20, 9 => 48],
            in_array($template, ['MAGICAL_DAMAGE', 'MAGICAL_DAMAGE_BUFF', 'MAGICAL_DAMAGE_REWARD'], true) => [1 => 8, 5 => 22, 9 => 52],
            $template === 'HYBRID_DAMAGE' => [1 => 8, 5 => 22, 9 => 52],
            in_array($template, ['GUARD_BARRIER', 'DAMAGE_GUARD_BARRIER'], true) || $category === 'guard' => [1 => 8, 5 => 22, 9 => 50],
            $category === 'buff' => [1 => 8, 5 => 20, 9 => 46],
            $category === 'debuff' || in_array($template, ['DAMAGE_DEBUFF', 'ENEMY_DEBUFF'], true) => [1 => 8, 5 => 20, 9 => 46],
            default => [1 => 6, 5 => 16, 9 => 42],
        };

        return $costs[$column];
    }

    private function rewardBonusPercentFor(array $row, string $key, string $template, int $power): int
    {
        if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
            return max(0, (int) $row[$key]);
        }

        if ($key === 'gold_bonus_percent' && JobArtEffectCatalog::appliesGoldBonus($template)) {
            return min(10, max(1, (int) floor($power / 20)));
        }

        if ($key === 'drop_bonus_percent' && JobArtEffectCatalog::appliesDropBonus($template)) {
            return min(8, max(1, (int) floor($power / 25)));
        }

        return 0;
    }
}
