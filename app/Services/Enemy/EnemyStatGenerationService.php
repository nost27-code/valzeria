<?php

namespace App\Services\Enemy;

class EnemyStatGenerationService
{
    /** @var array<string, mixed> */
    private array $config;

    public function __construct()
    {
        $this->config = config('enemy_stat_generation');
    }

    /**
     * @return array{
     *     enemy_level:int,
     *     family_key:string,
     *     variant_key:string,
     *     role_key:string,
     *     stat_generation_version:string,
     *     stats:array{hp:int,attack:int,defense:int,magic:int,spirit:int,speed:int,luck:int}
     * }
     */
    public function generate(int $enemyLevel, ?string $familyKey = null, ?string $variantKey = null, ?string $roleKey = null): array
    {
        $enemyLevel = $this->clampLevel($enemyLevel);
        $familyKey = $this->validKey('family_multipliers', $familyKey, 'family_key');
        $variantKey = $this->validKey('variant_multipliers', $variantKey, 'variant_key');
        $roleKey = $this->validKey('role_multipliers', $roleKey, 'role_key');

        $base = $this->baseStats($enemyLevel);
        $family = $this->multipliers('family_multipliers', $familyKey);
        $variant = $this->multipliers('variant_multipliers', $variantKey);
        $role = $this->multipliers('role_multipliers', $roleKey);
        $globalMultiplier = (float) ($this->config['global_stat_multiplier'] ?? 1.0);

        $stats = [];
        foreach ($base as $stat => $value) {
            $stats[$stat] = max(1, (int) round(
                $value
                * (float) ($family[$stat] ?? 1.0)
                * (float) ($variant[$stat] ?? 1.0)
                * (float) ($role[$stat] ?? 1.0)
                * $globalMultiplier
            ));
        }
        $stats = $this->applyOffenseFloors($stats, $familyKey);

        return [
            'enemy_level' => $enemyLevel,
            'family_key' => $familyKey,
            'variant_key' => $variantKey,
            'role_key' => $roleKey,
            'stat_generation_version' => (string) $this->config['version'],
            'stats' => $stats,
        ];
    }

    /**
     * @return array{hp:int,attack:int,defense:int,magic:int,spirit:int,speed:int,luck:int}
     */
    public function baseStats(int $enemyLevel): array
    {
        $enemyLevel = $this->clampLevel($enemyLevel);
        $x = $enemyLevel - 1;

        return [
            'hp' => max(1, (int) round(31 + 7.46 * $x + 0.518 * $x * $x)),
            'attack' => max(1, (int) round($this->lateOffenseValue($enemyLevel, 10.5, 1.03, 0.035, 'attack'))),
            'defense' => max(1, (int) round(4.5 + 0.57 * $x + 0.019 * $x * $x)),
            'magic' => max(1, (int) round($this->lateOffenseValue($enemyLevel, 4.0, 0.44, 0.011, 'magic'))),
            'spirit' => max(1, (int) round(4.0 + 0.44 * $x + 0.011 * $x * $x)),
            'speed' => max(1, (int) round(6.5 + 0.47 * $x + 0.016 * $x * $x)),
            'luck' => max(1, (int) round(2.5 + 0.20 * $x)),
        ];
    }

    private function lateOffenseValue(int $enemyLevel, float $base, float $linear, float $quadratic, string $stat): float
    {
        $curve = (array) ($this->config['late_offense_curve'] ?? []);
        $startLevel = max(1, (int) ($curve['start_level'] ?? 0));
        $x = $enemyLevel - 1;
        $startX = $startLevel - 1;

        if ($startLevel <= 1 || $enemyLevel <= $startLevel) {
            return $base + $linear * $x + $quadratic * $x * $x;
        }

        $linearScale = (float) ($curve[$stat . '_linear_scale'] ?? 1.0);
        $quadraticScale = (float) ($curve[$stat . '_quadratic_scale'] ?? 1.0);
        $valueAtStart = $base + $linear * $startX + $quadratic * $startX * $startX;
        $slopeAtStart = $linear + 2 * $quadratic * $startX;
        $delta = $enemyLevel - $startLevel;

        return $valueAtStart
            + $slopeAtStart * $linearScale * $delta
            + $quadratic * $quadraticScale * $delta * $delta;
    }

    public function version(): string
    {
        return (string) $this->config['version'];
    }

    public function clampLevel(int $level): int
    {
        return max(1, min((int) ($this->config['level_cap'] ?? 255), $level));
    }

    /**
     * @return list<string>
     */
    public function keys(string $group): array
    {
        return array_keys((array) ($this->config[$group] ?? []));
    }

    /**
     * @return array<string, float>
     */
    private function multipliers(string $group, string $key): array
    {
        return (array) ($this->config[$group][$key] ?? []);
    }

    private function validKey(string $group, ?string $key, string $defaultName): string
    {
        $default = (string) ($this->config['default_keys'][$defaultName] ?? '');
        $key = trim((string) $key);

        return array_key_exists($key, (array) ($this->config[$group] ?? [])) ? $key : $default;
    }

    /**
     * @param  array{hp:int,attack:int,defense:int,magic:int,spirit:int,speed:int,luck:int}  $stats
     * @return array{hp:int,attack:int,defense:int,magic:int,spirit:int,speed:int,luck:int}
     */
    private function applyOffenseFloors(array $stats, string $familyKey): array
    {
        $magicFloors = (array) ($this->config['offense_floors']['magic_vs_attack_by_family'] ?? []);
        $ratio = isset($magicFloors[$familyKey]) ? (float) $magicFloors[$familyKey] : 0.0;

        if ($ratio > 0) {
            $stats['magic'] = max($stats['magic'], (int) round($stats['attack'] * $ratio));
        }

        return $stats;
    }
}
