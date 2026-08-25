<?php

namespace App\Services\Nation;

use App\Services\GameSettingService;

final class NationWarSettingsService
{
    public const FACILITY_HP_D = ['wall' => 300, 'magic_cannon' => 90, 'logistics' => 120, 'arsenal' => 150, 'headquarters' => 500];
    public const LEVEL_HP_RATIOS = [1 => .20, 2 => .28, 3 => .37, 4 => .47, 5 => .58, 6 => .69, 7 => .79, 8 => .88, 9 => .95, 10 => 1.0];
    public const UPGRADE_BASE_COSTS = [1 => 200, 2 => 400, 3 => 700, 4 => 1200, 5 => 1800, 6 => 2600, 7 => 3600, 8 => 5000, 9 => 7000, 10 => 10000];
    public const UPGRADE_MULTIPLIERS = ['headquarters' => 1.0, 'wall' => 1.2, 'magic_cannon' => .8, 'logistics' => .8, 'arsenal' => .9];
    public const REBUILD_MULTIPLIERS = [1 => .8, 2 => 1.2, 3 => 1.8, 4 => 2.7];
    public const CANNON = [
        1 => ['e' => .55, 'ratio' => .150, 'turns' => [3,6,9,12,16,19,22,25,28]],
        2 => ['e' => .52, 'ratio' => .156, 'turns' => [3,6,9,12,15,19,22,25,28]],
        3 => ['e' => .49, 'ratio' => .161, 'turns' => [3,6,9,12,15,18,22,25,28]],
        4 => ['e' => .46, 'ratio' => .167, 'turns' => [3,6,9,12,15,17,20,23,26,29]],
        5 => ['e' => .43, 'ratio' => .172, 'turns' => [3,6,9,11,14,17,20,23,26,29]],
        6 => ['e' => .40, 'ratio' => .178, 'turns' => [3,5,8,11,13,16,19,22,24,27,30]],
        7 => ['e' => .37, 'ratio' => .183, 'turns' => [2,5,7,10,12,15,17,20,22,25,27,30]],
        8 => ['e' => .33, 'ratio' => .189, 'turns' => [2,5,7,9,11,14,16,18,21,23,25,28,30]],
        9 => ['e' => .29, 'ratio' => .194, 'turns' => [2,4,6,8,11,13,15,17,19,21,23,25,27,29]],
        10 => ['e' => .25, 'ratio' => .200, 'turns' => [2,4,6,7,9,11,13,15,17,19,21,22,24,26,28,30]],
    ];

    public function __construct(private readonly GameSettingService $settings) {}
    public function featureEnabled(): bool { return (bool) config('features.nation_war_enabled', false); }
    public function facilityUpgradesEnabled(): bool { return $this->settings->getBool('nation.facility_upgrades_enabled', false); }
    public function maxMembers(): int { return max(1, min(100, $this->settings->getInt('nation.max_members', 20))); }
    public function foundedProtectionDays(): int { return max(0, $this->settings->getInt('nation.founded_protection_days', 7)); }
    public function declarationEnabled(): bool { return $this->settings->getBool('nation_war.declaration_enabled', false); }
    public function referenceDamage(): int { return max(0, $this->settings->getInt('nation_war.reference_damage', 0)); }
    public function calibrated(): bool { return $this->referenceDamage() > 0; }
    public function preparationDays(): int { return max(1, $this->settings->getInt('nation_war.preparation_days', 3)); }
    public function durationDays(): int { return max(1, $this->settings->getInt('nation_war.duration_days', 5)); }
    public function sortiesPerDay(): int { return max(1, $this->settings->getInt('nation_war.sorties_per_day', 10)); }
    public function sortieStaminaCost(): int { return max(1, $this->settings->getInt('nation_war.sortie_stamina_cost', 15)); }
    public function maxTurns(): int { return max(1, $this->settings->getInt('nation_war.max_turns', 30)); }
    public function repairPointsPerD(): int { return max(1, $this->settings->getInt('nation_war.repair_points_per_d', 140)); }
    public function rebuildMinutes(): int { return max(1, $this->settings->getInt('nation_war.rebuild_minutes', 60)); }
    public function deathExtraSorties(): int { return max(0, $this->settings->getInt('nation_war.death_extra_sorties', 1)); }
    public function lossProtectionDays(): int { return max(0, $this->settings->getInt('nation_war.loss_protection_days', 3)); }
    public function logisticsSelfRepairMultiplier(): float { return max(1, $this->settings->getFloat('nation_war.logistics_self_repair_multiplier', 2)); }
    public function rebuildHpBps(): int { return max(1, min(10000, $this->settings->getInt('nation_war.rebuild_hp_bps', 5000))); }
    public function facilityBaseD(string $type): int { return max(1, $this->settings->getInt("nation_war.facility_base_d.{$type}", self::FACILITY_HP_D[$type] ?? 1)); }
    public function levelHpRatio(int $level): float { $level = max(1, min(10, $level)); return max(.0001, min(1, $this->settings->getInt("nation_war.facility_level_ratio_bps.{$level}", (int) round(self::LEVEL_HP_RATIOS[$level] * 10000)) / 10000)); }
    public function rebuildMultiplier(int $rebuildCount): float
    {
        if ($rebuildCount < 4) return max(.01, $this->settings->getFloat('nation_war.rebuild_multiplier.'.($rebuildCount + 1), self::REBUILD_MULTIPLIERS[$rebuildCount + 1]));
        return max(.01, $this->settings->getFloat('nation_war.rebuild_multiplier.4', 2.7)) * (max(1, $this->settings->getFloat('nation_war.rebuild_escalation_multiplier', 1.5)) ** ($rebuildCount - 3));
    }
    /** @return array{e:float,ratio:float,turns:list<int>} */
    public function cannonSpec(int $level): array
    {
        $level = max(1, min(10, $level)); $default = self::CANNON[$level];
        $turns = array_values(array_filter(array_map('intval', explode(',', $this->settings->getString("nation_war.cannon_fire_turns.{$level}", implode(',', $default['turns'])))), fn (int $turn) => $turn >= 1 && $turn <= 30));
        return ['e' => max(0, $this->settings->getFloat("nation_war.cannon_target_e.{$level}", $default['e'])), 'ratio' => max(.0001, $this->settings->getInt("nation_war.cannon_damage_ratio_bps.{$level}", (int) round($default['ratio'] * 10000)) / 10000), 'turns' => array_values(array_unique($turns))];
    }
    public function cannonDirectHitRate(): float { return max(0, min(100, $this->settings->getFloat('nation_war.cannon_direct_hit_rate', 10))); }
    public function cannonDirectHitMultiplier(): float { return max(1, $this->settings->getFloat('nation_war.cannon_direct_hit_multiplier', 2.5)); }
}
