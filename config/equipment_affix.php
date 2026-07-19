<?php

return [
    'maximum_level' => 5,

    'quality_multipliers' => [
        'normal' => 1.00,
        'good' => 1.15,
        'excellent' => 1.35,
    ],

    // 鍛冶での武器品質昇格率（1/10000）。武器の+強化、銘鍛錬、特攻磨きの成功時だけ抽選する。
    'forge_quality_upgrade_rates_bps' => [
        'good' => 100, // 1%
        'excellent' => 10, // 0.1%
    ],

    // 全能力を上げる「調律の」銘は、単能力の銘と比べて各能力を55%に抑える。
    'all_stat_multiplier' => 0.55,

    // 銘の段階別基礎性能補正率。武器の新固定値仕様(equipment_scaling.php)とセットで
    // 有効化する新仕様(6/12/18/24/30%)と、現行仕様(8/16/24/32/40%)を
    // 環境変数で切り替える。デフォルトは現行維持（本番有効化は別途確認後に.envでtrueにする）。
    // テスト環境は phpunit.xml でtrueに固定し、新仕様の値を検証する。
    'engraving_effect_rates' => env('EQUIPMENT_ENGRAVING_NEW_RATES_ENABLED', false)
        ? [1 => 0.06, 2 => 0.12, 3 => 0.18, 4 => 0.24, 5 => 0.30]
        : [1 => 0.08, 2 => 0.16, 3 => 0.24, 4 => 0.32, 5 => 0.40],

    'killer_damage_rates' => [
        1 => 0.06,
        2 => 0.12,
        3 => 0.18,
        4 => 0.24,
        5 => 0.30,
    ],

    'maximum_level_by_equipment_rank' => [
        'G' => 2,
        'F' => 2,
        'E' => 2,
        'D' => 2,
        'C' => 2,
        'B' => 2,
        'A' => 3,
        'S' => 4,
        'SS' => 5,
        'SSS' => 5,
        'EPIC' => 5,
    ],

    'minimum_equipment_rank_by_level' => [
        1 => 'G',
        2 => 'G',
        3 => 'A',
        4 => 'S',
        5 => 'SS',
    ],

    'forge' => [
        'single_gold_costs' => [
            2 => 20_000,
            3 => 80_000,
            4 => 250_000,
            5 => 750_000,
        ],
        'dual_discount_rate' => 0.80,
    ],

    'transfer' => [
        'gold_costs' => [
            1 => 5_000,
            2 => 10_000,
            3 => 30_000,
            4 => 80_000,
            5 => 200_000,
        ],
    ],
];
