<?php

namespace App\Services\Nation\Raid;

use App\Services\CharacterStatusService;
use Closure;
use DomainException;

/** Relic activation and combat semantics are part of the approved raid, never a live toggle. */
final class NationRaidRelicRules
{
    public const MODEL = 'nameless-raid-combat-v1';
    public const SETTINGS = ['stat_rate_cap', 'resist_cap', 'chase_damage_rate', 'counter_damage_rate',
        'mirror_return_rate', 'low_hp_threshold', 'profiles', 'special_values', 'weapon_power_at_max',
        'armor_power_at_max', 'armor_stat_targets_at_max', 'accessory_stat_targets_at_max'];

    public function current(): array
    {
        // The rules catalog is also used by pure CLI/unit consumers before Laravel boots.
        $booted = \Illuminate\Container\Container::getInstance()->bound('config');
        $values = $booted ? (array) config('nameless_relics') : require dirname(__DIR__, 4).'/config/nameless_relics.php';
        $definitions = $booted ? (array) config('nameless_relic_effects') : require dirname(__DIR__, 4).'/config/nameless_relic_effects.php';
        $settings = [];
        foreach (self::SETTINGS as $key) {
            $settings[$key] = $values[$key];
        }
        $effects = [];
        foreach ($definitions as $key => $effect) {
            $effects[$key] = array_diff_key($effect, array_flip(['name', 'description', 'zone']));
        }
        return ['model' => self::MODEL, 'snapshot_version' => 1,
            'enabled' => (bool) ($values['enabled'] ?? false), 'settings' => $settings, 'effects' => $effects];
    }

    /** Legacy approved events had no relic contract and are OFF, without rewriting their hash. */
    public function forRuleset(array $snapshot): array
    {
        return $snapshot['nameless_relic_combat'] ?? ['model' => 'legacy-off', 'enabled' => false];
    }

    public function assertCurrent(array $snapshot): void
    {
        $contract = $this->forRuleset($snapshot);
        $current = $this->current();
        $matches = ($contract['model'] ?? null) === 'legacy-off'
            ? ! $current['enabled'] : $contract === $current;
        if (! $matches) {
            throw new DomainException('遺物の戦闘ルールが承認時から変更されています。再シミュレーションと新しい開催承認が必要です。');
        }
        if ($current['enabled'] && ! app(\App\Services\NamelessWorkshopService::class)->schemaReady()) {
            throw new DomainException('遺物DBの準備を確認中のため、新しい出撃を停止しています。');
        }
    }

    /** Process-local scope only. Always restore both config and cached character statistics. */
    public function frozen(array $contract, Closure $callback): mixed
    {
        if (($contract['model'] ?? null) === 'legacy-off') {
            throw_unless(($contract['enabled'] ?? null) === false, DomainException::class, '旧レイドの遺物ルールが不正です。');
            $overrides = ['nameless_relics.enabled' => false];
        } else {
            throw_unless(($contract['model'] ?? null) === self::MODEL && ($contract['snapshot_version'] ?? null) === 1
                && is_bool($contract['enabled'] ?? null) && is_array($contract['settings'] ?? null)
                && array_keys($contract['settings']) === self::SETTINGS && is_array($contract['effects'] ?? null),
                DomainException::class, '未対応の遺物戦闘ルールです。');
            $definitions = [];
            foreach ($contract['effects'] as $key => $effect) {
                $definitions[$key] = [...array_intersect_key(config('nameless_relic_effects.'.$key) ?? ['name' => $key], array_flip(['name', 'description', 'zone'])), ...$effect];
            }
            $overrides = ['nameless_relics.enabled' => $contract['enabled'], 'nameless_relic_effects' => $definitions];
            foreach ($contract['settings'] as $key => $value) {
                $overrides['nameless_relics.'.$key] = $value;
            }
        }
        $original = [];
        foreach ($overrides as $key => $value) { $original[$key] = config($key); }
        CharacterStatusService::clearRequestCache();
        config($overrides);
        try {
            return $callback();
        } finally {
            config($original);
            CharacterStatusService::clearRequestCache();
        }
    }
}
