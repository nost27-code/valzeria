<?php

namespace App\Services;

use App\Models\Enemy;

/**
 * 都市帯・敵役割ごとの耐久補正倍率を一箇所で解決する。
 * BattleService::enemyBattleStats() が戦闘実行と画面表示(enemyStatDisplay)の
 * 両方でこのクラスを経由するため、表示値と実戦値が乖離することはない。
 */
class EnemyDurabilityService
{
    private const NEUTRAL = ['hp' => 1.0, 'def_spr' => 1.0, 'atk_mag' => 1.0];

    /**
     * @return array{hp: float, def_spr: float, atk_mag: float, tier: string, role: string}
     */
    public function multiplierFor(Enemy $enemy, int $cityId): array
    {
        if (! (bool) config('enemy_durability.enabled', true)) {
            return [...self::NEUTRAL, 'tier' => 'disabled', 'role' => (string) ($enemy->role_key ?? '')];
        }

        $roleKey = (string) ($enemy->role_key ?? 'normal');

        if (in_array((int) $enemy->id, (array) config('enemy_durability.safe_enemy_ids', []), true)) {
            return [...self::NEUTRAL, 'tier' => 'safe_enemy', 'role' => $roleKey];
        }

        if (in_array($roleKey, (array) config('enemy_durability.excluded_roles', []), true)) {
            return [...self::NEUTRAL, 'tier' => 'excluded_role', 'role' => $roleKey];
        }

        $superBossThreshold = (int) config('enemy_durability.super_boss_level_threshold', 200);
        if ((bool) $enemy->is_boss && (int) $enemy->level >= $superBossThreshold) {
            $tierConfig = (array) config('enemy_durability.tiers.super_boss', []);
            if (! (bool) ($tierConfig['enabled'] ?? true)) {
                return [...self::NEUTRAL, 'tier' => 'super_boss_disabled', 'role' => $roleKey];
            }

            $mul = (array) ($tierConfig['roles']['boss'] ?? []);

            return [
                'hp' => (float) ($mul['hp'] ?? 1.0),
                'def_spr' => (float) ($mul['def_spr'] ?? 1.0),
                'atk_mag' => (float) ($mul['atk_mag'] ?? 1.0),
                'tier' => 'super_boss',
                'role' => $roleKey,
            ];
        }

        $tierKey = match (true) {
            $cityId === 8 => 'city8',
            $cityId === 9 => 'city9',
            $cityId === 10 => 'city10',
            in_array($cityId, (array) config('enemy_durability.tiers.hikyo.city_ids', [101, 102, 103]), true) => 'hikyo',
            default => null,
        };

        if ($tierKey === null) {
            return [...self::NEUTRAL, 'tier' => 'unmanaged', 'role' => $roleKey];
        }

        $tierConfig = (array) config("enemy_durability.tiers.{$tierKey}", []);
        if (! (bool) ($tierConfig['enabled'] ?? true)) {
            return [...self::NEUTRAL, 'tier' => "{$tierKey}_disabled", 'role' => $roleKey];
        }

        $roleForLookup = array_key_exists($roleKey, (array) ($tierConfig['roles'] ?? [])) ? $roleKey : 'normal';
        $mul = (array) ($tierConfig['roles'][$roleForLookup] ?? []);

        return [
            'hp' => (float) ($mul['hp'] ?? 1.0),
            'def_spr' => (float) ($mul['def_spr'] ?? 1.0),
            'atk_mag' => (float) ($mul['atk_mag'] ?? 1.0),
            'tier' => $tierKey,
            'role' => $roleKey,
        ];
    }
}
