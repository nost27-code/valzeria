<?php

namespace Database\Seeders;

use App\Models\Skill;
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
                    'damage_type' => $this->damageTypeForTemplate($template),
                    'power_multiplier' => max(0, $power / 100),
                    'hit_count' => $template === 'MULTI_HIT' ? 2 : ($this->isPureSupport($template) ? 0 : 1),
                    'heal_percent' => 0,
                    'mp_recover_percent' => (int) ($row['mp_recover_percent'] ?? 0),
                    'gold_bonus_percent' => $template === 'REWARD_GOLD' || $template === 'REWARD_MIXED' ? min(10, max(1, (int) floor($power / 20))) : 0,
                    'drop_bonus_percent' => in_array($template, ['REWARD_DROP', 'REWARD_MIXED'], true) ? min(8, max(1, (int) floor($power / 25))) : 0,
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

    private function damageTypeForTemplate(string $template): string
    {
        return match ($template) {
            'MAGICAL_DAMAGE', 'MAGICAL_DAMAGE_BUFF' => 'magical',
            'HYBRID_DAMAGE' => 'hybrid',
            'HEAL', 'HEAL_CLEANSE' => 'heal',
            'SELF_BUFF', 'ENEMY_DEBUFF', 'GUARD_BARRIER', 'GUTS', 'TIME_CONTROL_CURRENT_ONLY' => 'support',
            'REWARD_GOLD' => 'gold',
            'REWARD_DROP', 'REWARD_MIXED' => 'drop',
            default => 'physical',
        };
    }

    private function isPureSupport(string $template): bool
    {
        return in_array($template, ['HEAL', 'HEAL_CLEANSE', 'SELF_BUFF', 'ENEMY_DEBUFF', 'GUARD_BARRIER', 'GUTS', 'REWARD_GOLD', 'REWARD_DROP', 'REWARD_MIXED', 'TIME_CONTROL_CURRENT_ONLY'], true);
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
            $limitGroup === 'REWARD' || str_starts_with($template, 'REWARD_') => [1 => 12, 5 => 30, 9 => 65],
            $limitGroup === 'GUTS' || $template === 'GUTS' => [1 => 12, 5 => 30, 9 => 65],
            $limitGroup === 'HEAL' || in_array($template, ['HEAL', 'HEAL_CLEANSE'], true) => [1 => 10, 5 => 26, 9 => 60],
            $template === 'DRAIN' => [1 => 10, 5 => 28, 9 => 62],
            $template === 'MULTI_HIT' => [1 => 8, 5 => 20, 9 => 48],
            in_array($template, ['MAGICAL_DAMAGE', 'MAGICAL_DAMAGE_BUFF'], true) => [1 => 8, 5 => 22, 9 => 52],
            $template === 'HYBRID_DAMAGE' => [1 => 8, 5 => 22, 9 => 52],
            $template === 'GUARD_BARRIER' || $category === 'guard' => [1 => 8, 5 => 22, 9 => 50],
            $category === 'buff' => [1 => 8, 5 => 20, 9 => 46],
            $category === 'debuff' || in_array($template, ['DAMAGE_DEBUFF', 'ENEMY_DEBUFF'], true) => [1 => 8, 5 => 20, 9 => 46],
            default => [1 => 6, 5 => 16, 9 => 42],
        };

        return $costs[$column];
    }
}
