<?php

return [
    'version' => 'enemy_curve_v1_9_1_2026_06',
    'global_stat_multiplier' => 1.25,
    'level_cap' => 255,
    'city_surface_level_width' => 14,
    'dungeons_per_city' => 7,

    'layer_offsets' => [
        'surface' => 0,
        'deep' => 14,
        'depths' => 28,
        'deepest' => 42,
        'otherworld' => 70,
        'core' => 96,
    ],

    'default_keys' => [
        'family_key' => 'standard',
        'variant_key' => 'none',
        'role_key' => 'normal',
        'layer_key' => 'surface',
    ],

    'role_level_offsets' => [
        'normal_weak' => 0,
        'normal' => 1,
        'strong' => 1,
        'rare' => null,
        'golden' => null,
        'deep_candidate' => null,
        'dungeon_lord' => 3,
        'boss' => 0,
        'city_boss' => 2,
        'otherworld_boss' => 5,
    ],

    'family_multipliers' => [
        'standard' => ['hp' => 1.00, 'attack' => 1.00, 'defense' => 1.00, 'magic' => 1.00, 'spirit' => 1.00, 'speed' => 1.00, 'luck' => 1.00],
        'slime' => ['hp' => 1.15, 'attack' => 0.80, 'defense' => 0.80, 'magic' => 0.75, 'spirit' => 0.90, 'speed' => 0.75, 'luck' => 1.00],
        'beast' => ['hp' => 0.95, 'attack' => 1.15, 'defense' => 0.85, 'magic' => 0.60, 'spirit' => 0.75, 'speed' => 1.25, 'luck' => 1.10],
        'goblin' => ['hp' => 0.95, 'attack' => 1.05, 'defense' => 0.90, 'magic' => 0.80, 'spirit' => 0.85, 'speed' => 1.05, 'luck' => 1.15],
        'soldier' => ['hp' => 1.00, 'attack' => 1.05, 'defense' => 1.05, 'magic' => 0.80, 'spirit' => 0.95, 'speed' => 1.00, 'luck' => 1.00],
        'mage' => ['hp' => 0.80, 'attack' => 0.55, 'defense' => 0.70, 'magic' => 1.35, 'spirit' => 1.25, 'speed' => 0.95, 'luck' => 1.05],
        'spirit' => ['hp' => 0.85, 'attack' => 0.60, 'defense' => 0.75, 'magic' => 1.30, 'spirit' => 1.35, 'speed' => 1.10, 'luck' => 1.15],
        'undead' => ['hp' => 1.10, 'attack' => 0.95, 'defense' => 0.90, 'magic' => 1.05, 'spirit' => 0.65, 'speed' => 0.80, 'luck' => 0.75],
        'giant' => ['hp' => 1.30, 'attack' => 1.05, 'defense' => 1.35, 'magic' => 0.55, 'spirit' => 1.05, 'speed' => 0.60, 'luck' => 0.80],
        'insect' => ['hp' => 1.05, 'attack' => 1.05, 'defense' => 1.15, 'magic' => 0.55, 'spirit' => 0.70, 'speed' => 0.95, 'luck' => 0.90],
        'flying' => ['hp' => 0.85, 'attack' => 0.95, 'defense' => 0.75, 'magic' => 0.80, 'spirit' => 0.80, 'speed' => 1.45, 'luck' => 1.20],
        'aquatic' => ['hp' => 1.05, 'attack' => 0.95, 'defense' => 1.00, 'magic' => 1.05, 'spirit' => 1.10, 'speed' => 0.95, 'luck' => 1.00],
        'dragon' => ['hp' => 1.45, 'attack' => 1.25, 'defense' => 1.20, 'magic' => 1.15, 'spirit' => 1.10, 'speed' => 0.85, 'luck' => 0.90],
        'demon' => ['hp' => 1.05, 'attack' => 1.15, 'defense' => 0.95, 'magic' => 1.20, 'spirit' => 1.05, 'speed' => 1.05, 'luck' => 1.05],
        'machine' => ['hp' => 1.20, 'attack' => 1.10, 'defense' => 1.30, 'magic' => 0.75, 'spirit' => 0.80, 'speed' => 0.70, 'luck' => 0.60],
    ],

    'variant_multipliers' => [
        'none' => ['hp' => 1.00, 'attack' => 1.00, 'defense' => 1.00, 'magic' => 1.00, 'spirit' => 1.00, 'speed' => 1.00, 'luck' => 1.00],
        'fire' => ['hp' => 0.95, 'attack' => 1.10, 'defense' => 0.95, 'magic' => 1.10, 'spirit' => 0.95, 'speed' => 1.00, 'luck' => 1.00],
        'ice' => ['hp' => 1.05, 'attack' => 0.95, 'defense' => 1.10, 'magic' => 1.05, 'spirit' => 1.10, 'speed' => 0.90, 'luck' => 1.00],
        'thunder' => ['hp' => 0.95, 'attack' => 1.05, 'defense' => 0.95, 'magic' => 1.10, 'spirit' => 0.95, 'speed' => 1.15, 'luck' => 1.05],
        'poison' => ['hp' => 1.00, 'attack' => 1.05, 'defense' => 0.95, 'magic' => 1.10, 'spirit' => 1.05, 'speed' => 0.95, 'luck' => 1.15],
        'holy' => ['hp' => 1.05, 'attack' => 0.95, 'defense' => 1.00, 'magic' => 1.10, 'spirit' => 1.15, 'speed' => 1.00, 'luck' => 1.05],
        'dark' => ['hp' => 1.00, 'attack' => 1.10, 'defense' => 0.95, 'magic' => 1.15, 'spirit' => 0.90, 'speed' => 1.05, 'luck' => 1.10],
        'earth' => ['hp' => 1.10, 'attack' => 1.00, 'defense' => 1.15, 'magic' => 0.90, 'spirit' => 1.00, 'speed' => 0.85, 'luck' => 0.95],
        'water' => ['hp' => 1.05, 'attack' => 0.95, 'defense' => 1.00, 'magic' => 1.05, 'spirit' => 1.10, 'speed' => 1.00, 'luck' => 1.00],
        'forest' => ['hp' => 1.05, 'attack' => 1.00, 'defense' => 0.95, 'magic' => 1.00, 'spirit' => 1.05, 'speed' => 1.05, 'luck' => 1.05],
        'arcane' => ['hp' => 0.95, 'attack' => 0.90, 'defense' => 0.90, 'magic' => 1.20, 'spirit' => 1.15, 'speed' => 1.00, 'luck' => 1.05],
        'ancient' => ['hp' => 1.10, 'attack' => 1.05, 'defense' => 1.15, 'magic' => 1.05, 'spirit' => 1.10, 'speed' => 0.90, 'luck' => 0.90],
        'abyss' => ['hp' => 1.15, 'attack' => 1.10, 'defense' => 1.05, 'magic' => 1.15, 'spirit' => 0.95, 'speed' => 1.00, 'luck' => 1.10],
        'metal' => ['hp' => 1.15, 'attack' => 0.95, 'defense' => 1.35, 'magic' => 0.70, 'spirit' => 0.90, 'speed' => 0.70, 'luck' => 0.70],
        'ghost' => ['hp' => 0.85, 'attack' => 0.80, 'defense' => 0.70, 'magic' => 1.20, 'spirit' => 1.25, 'speed' => 1.10, 'luck' => 0.90],
    ],

    'role_multipliers' => [
        'normal_weak' => ['hp' => 1.05, 'attack' => 1.05, 'defense' => 1.05, 'magic' => 1.05, 'spirit' => 1.05, 'speed' => 1.05, 'luck' => 1.00],
        'normal' => ['hp' => 1.10, 'attack' => 1.10, 'defense' => 1.10, 'magic' => 1.10, 'spirit' => 1.10, 'speed' => 1.10, 'luck' => 1.10],
        'strong' => ['hp' => 1.16, 'attack' => 1.17, 'defense' => 1.15, 'magic' => 1.17, 'spirit' => 1.15, 'speed' => 1.15, 'luck' => 1.20],
        'rare' => ['hp' => 1.00, 'attack' => 1.10, 'defense' => 1.00, 'magic' => 1.10, 'spirit' => 1.00, 'speed' => 1.10, 'luck' => 1.35],
        'golden' => ['hp' => 0.45, 'attack' => 0.60, 'defense' => 0.55, 'magic' => 0.40, 'spirit' => 0.55, 'speed' => 1.65, 'luck' => 2.00],
        'deep_candidate' => ['hp' => 1.15, 'attack' => 1.12, 'defense' => 1.08, 'magic' => 1.03, 'spirit' => 1.03, 'speed' => 1.02, 'luck' => 1.15],
        'dungeon_lord' => ['hp' => 1.65, 'attack' => 1.30, 'defense' => 1.15, 'magic' => 1.12, 'spirit' => 1.08, 'speed' => 1.02, 'luck' => 1.25],
        'boss' => ['hp' => 1.75, 'attack' => 1.45, 'defense' => 1.25, 'magic' => 1.16, 'spirit' => 1.12, 'speed' => 1.03, 'luck' => 1.30],
        'city_boss' => ['hp' => 1.65, 'attack' => 1.32, 'defense' => 1.18, 'magic' => 1.22, 'spirit' => 1.18, 'speed' => 1.04, 'luck' => 1.45],
        'otherworld_boss' => ['hp' => 1.90, 'attack' => 1.40, 'defense' => 1.25, 'magic' => 1.32, 'spirit' => 1.25, 'speed' => 1.06, 'luck' => 1.60],
    ],

    'late_offense_curve' => [
        'start_level' => 65,
        'attack_linear_scale' => 0.75,
        'attack_quadratic_scale' => 0.45,
        'magic_linear_scale' => 0.80,
        'magic_quadratic_scale' => 0.55,
    ],

    'offense_floors' => [
        // MAG基礎曲線はATKより低いため、魔法系だけ最低火力を保証する。
        'magic_vs_attack_by_family' => [
            'mage' => 1.55,
            'spirit' => 1.35,
            'dragon' => 0.95,
            'demon' => 1.18,
        ],
        // 行動パターン上、物理と魔法を両方使う型は、片方が完全な死に手にならないようにする。
        'hybrid_offense_by_type' => [
            '魔法型' => [
                'primary' => 'mag',
                'secondary' => 'str',
                'primary_vs_secondary' => 1.10,
                'secondary_vs_primary' => 0.45,
            ],
            '竜型' => [
                'primary' => 'str',
                'secondary' => 'mag',
                'secondary_vs_primary' => 0.95,
            ],
        ],
        // 通常進行のボスは、前ダンジョンのボス火力を下回らないようにする。
        'boss_progression' => [
            'enabled' => true,
            'max_recommended_level' => 179,
            'minimum_growth_rate' => 0.03,
            'late_growth_start_level' => 65,
            'late_minimum_growth_rate' => 0.01,
            'minimum_growth_flat' => 1,
        ],
    ],
];
