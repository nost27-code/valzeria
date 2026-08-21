<?php

namespace App\Support;

final class PlayerStatLabel
{
    private const LABELS = [
        'hp' => 'HP',
        'max_hp' => 'HP',
        'mp' => 'SP',
        'sp' => 'SP',
        'max_mp' => 'SP',
        'str' => '攻撃',
        'atk' => '攻撃',
        '攻撃' => '攻撃',
        '攻撃力' => '攻撃',
        'def' => '防御',
        '防御' => '防御',
        '防御力' => '防御',
        'mag' => '魔力',
        '魔力' => '魔力',
        '魔法力' => '魔力',
        'spr' => '精神',
        '精神' => '精神',
        '精神力' => '精神',
        'agi' => '敏捷',
        'spd' => '敏捷',
        '敏捷' => '敏捷',
        '素早さ' => '敏捷',
        'luk' => '運',
        'luck' => '運',
        '運' => '運',
    ];

    public static function for(string $stat): string
    {
        $trimmed = trim($stat);
        $normalized = strtolower($trimmed);

        return self::LABELS[$normalized] ?? self::LABELS[$trimmed] ?? $trimmed;
    }
}
