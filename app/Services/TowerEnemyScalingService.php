<?php

namespace App\Services;

use App\Models\TowerFloorMaster;
use InvalidArgumentException;

class TowerEnemyScalingService
{
    /**
     * @return array{max_hp:int,str:int,def:int,mag:int,spr:int,agi:int,luk:int}
     */
    public function baseStatsForFloor(int $floor): array
    {
        $this->assertValidFloor($floor);

        $settings = config('star_tree_tower.star_tree.enemy_scaling', []);
        $lateThreshold = (int) ($settings['late_floor_threshold'] ?? 40);
        $late = max(0, $floor - $lateThreshold);

        return [
            'max_hp' => $this->scaleStat($settings['max_hp'] ?? [], $floor, $late),
            'str' => $this->scaleStat($settings['str'] ?? [], $floor, $late),
            'def' => $this->scaleStat($settings['def'] ?? [], $floor, $late),
            'mag' => $this->scaleStat($settings['mag'] ?? [], $floor, $late),
            'spr' => $this->scaleStat($settings['spr'] ?? [], $floor, $late),
            'agi' => $this->scaleStat($settings['agi'] ?? [], $floor, $late),
            'luk' => $this->scaleStat($settings['luk'] ?? [], $floor, $late),
        ];
    }

    /**
     * @return array{max_hp:int,str:int,def:int,mag:int,spr:int,agi:int,luk:int}
     */
    public function statsForFloor(int $floor, string $enemyProfile): array
    {
        return $this->applyProfile($this->baseStatsForFloor($floor), $enemyProfile);
    }

    /**
     * @return array{max_hp:int,str:int,def:int,mag:int,spr:int,agi:int,luk:int}
     */
    public function statsForFloorMaster(TowerFloorMaster $floorMaster): array
    {
        return $this->statsForFloor((int) $floorMaster->floor, (string) $floorMaster->enemy_profile);
    }

    /**
     * @param array{max_hp:int,str:int,def:int,mag:int,spr:int,agi:int,luk:int} $stats
     * @return array{max_hp:int,str:int,def:int,mag:int,spr:int,agi:int,luk:int}
     */
    public function applyProfile(array $stats, string $enemyProfile): array
    {
        $profile = $this->normalizeProfile($enemyProfile);
        $multipliers = config("star_tree_tower.star_tree.enemy_profile_multipliers.{$profile}", []);

        foreach ($stats as $key => $value) {
            $stats[$key] = (int) round($value * (float) ($multipliers[$key] ?? 1.0));
        }

        return $stats;
    }

    public function normalizeProfile(string $enemyProfile): string
    {
        $profiles = array_keys(config('star_tree_tower.star_tree.enemy_profile_multipliers', []));

        if (in_array($enemyProfile, $profiles, true)) {
            return $enemyProfile;
        }

        return 'physical';
    }

    /**
     * @param array{base?:int|float,floor?:int|float,floor_square?:int|float,late_square?:int|float} $setting
     */
    private function scaleStat(array $setting, int $floor, int $late): int
    {
        $value = (float) ($setting['base'] ?? 0)
            + $floor * (float) ($setting['floor'] ?? 0)
            + $floor * $floor * (float) ($setting['floor_square'] ?? 0)
            + $late * $late * (float) ($setting['late_square'] ?? 0);

        return (int) round($value);
    }

    private function assertValidFloor(int $floor): void
    {
        if ($floor < 1) {
            throw new InvalidArgumentException('Tower floor must be 1 or greater.');
        }
    }
}
