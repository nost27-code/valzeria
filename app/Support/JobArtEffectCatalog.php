<?php

namespace App\Support;

class JobArtEffectCatalog
{
    private const DEFINITIONS = [
        'PHYSICAL_DAMAGE' => ['label' => '攻撃', 'damage_type' => 'physical', 'deals_damage' => true],
        'MAGICAL_DAMAGE' => ['label' => '攻撃', 'damage_type' => 'magical', 'deals_damage' => true],
        'HYBRID_DAMAGE' => ['label' => '攻撃', 'damage_type' => 'hybrid', 'deals_damage' => true],
        'MULTI_HIT' => ['label' => '連撃', 'damage_type' => 'physical', 'deals_damage' => true, 'hit_count' => 2],
        'DAMAGE_BUFF' => ['label' => '攻撃+バフ', 'damage_type' => 'physical', 'deals_damage' => true],
        'MAGICAL_DAMAGE_BUFF' => ['label' => '魔法+バフ', 'damage_type' => 'magical', 'deals_damage' => true],
        'DAMAGE_DEBUFF' => ['label' => '攻撃+デバフ', 'damage_type' => 'physical', 'deals_damage' => true],
        'DAMAGE_GUARD_BARRIER' => ['label' => '攻撃+防御', 'damage_type' => 'physical', 'deals_damage' => true],
        'SELF_BUFF' => ['label' => 'バフ', 'damage_type' => 'support'],
        'ENEMY_DEBUFF' => ['label' => 'デバフ', 'damage_type' => 'support'],
        'GUARD_BARRIER' => ['label' => '防御', 'damage_type' => 'support'],
        'HEAL' => ['label' => '回復', 'damage_type' => 'heal'],
        'HEAL_CLEANSE' => ['label' => '回復', 'damage_type' => 'heal'],
        'DRAIN' => ['label' => '吸収', 'damage_type' => 'magical', 'deals_damage' => true],
        'GUTS' => ['label' => '踏みとどまり', 'damage_type' => 'support'],
        'REWARD_GOLD' => ['label' => '報酬', 'damage_type' => 'gold', 'gold_bonus' => true],
        'REWARD_DROP' => ['label' => '報酬', 'damage_type' => 'drop', 'drop_bonus' => true],
        'REWARD_MIXED' => ['label' => '報酬', 'damage_type' => 'drop', 'gold_bonus' => true, 'drop_bonus' => true],
        'PHYSICAL_DAMAGE_GOLD_REWARD' => ['label' => '攻撃+Gold', 'damage_type' => 'physical', 'deals_damage' => true, 'gold_bonus' => true],
        'PHYSICAL_DAMAGE_REWARD' => ['label' => '攻撃+報酬', 'damage_type' => 'physical', 'deals_damage' => true, 'gold_bonus' => true, 'drop_bonus' => true],
        'MAGICAL_DAMAGE_REWARD' => ['label' => '魔法+報酬', 'damage_type' => 'magical', 'deals_damage' => true, 'gold_bonus' => true, 'drop_bonus' => true],
        'TIME_CONTROL_CURRENT_ONLY' => ['label' => '時空', 'damage_type' => 'support'],
    ];

    public static function templates(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    public static function has(string $template): bool
    {
        return array_key_exists($template, self::DEFINITIONS);
    }

    public static function dealsDamage(string $template): bool
    {
        return (bool) (self::DEFINITIONS[$template]['deals_damage'] ?? false);
    }

    public static function isPureSupport(string $template): bool
    {
        return ! self::dealsDamage($template);
    }

    public static function damageType(string $template): string
    {
        return (string) (self::DEFINITIONS[$template]['damage_type'] ?? 'physical');
    }

    public static function hitCount(string $template): int
    {
        if (self::isPureSupport($template)) {
            return 0;
        }

        return (int) (self::DEFINITIONS[$template]['hit_count'] ?? 1);
    }

    public static function appliesGoldBonus(string $template): bool
    {
        return (bool) (self::DEFINITIONS[$template]['gold_bonus'] ?? false);
    }

    public static function appliesDropBonus(string $template): bool
    {
        return (bool) (self::DEFINITIONS[$template]['drop_bonus'] ?? false);
    }

    public static function label(string $template): ?string
    {
        return self::DEFINITIONS[$template]['label'] ?? null;
    }
}
