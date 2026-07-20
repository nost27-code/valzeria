<?php

return [
    'granberg_black_furnace' => [
        'enabled' => true,
        'name' => '黒炉深坑',
        'city_id' => 4,
        'area_key' => 'granberg_black_furnace',
        'danger_increase_percent' => 33,
        'entry' => [
            'gold' => 10000,
            'materials' => [
                ['code' => 'WEV0026', 'quantity' => 2],
                ['code' => '5031', 'quantity' => 2],
                ['code' => 'WEV0039', 'quantity' => 1],
                ['code' => '5032', 'quantity' => 1],
            ],
        ],
        'scaling' => [
            // サンドラ入口「砂海」の通常敵平均を基準にした、黒炉深坑の初期戦力。
            // 危険度による伸びとは別に適用するため、潜行開始直後から高難度になる。
            'base_stat_multipliers' => [
                'hp' => 1.427,
                'str' => 1.397,
                'def' => 1.388,
                'agi' => 1.484,
                'mag' => 1.208,
                'spr' => 1.287,
                'luk' => 1.234,
            ],
            // 砂海の通常敵EXP平均 2,091.4 / 黒炉深坑の基礎EXP平均 1,555。
            // この基準値へ危険度ボーナスを重ねる。
            'base_exp_multiplier' => 1.345,
            'base_job_exp' => 3,
            'main_stat_per_danger' => 0.01,
            'hp_per_danger' => 0.005,
            'agi_luk_per_danger' => 0.005,
            'exp_per_danger' => 0.0005,
            'exp_multiplier_cap' => 2.0,
        ],
        'job_exp' => [
            'cap' => 8,
            'danger_per_guaranteed_bonus' => 200,
            'remainder_percent_divisor' => 2,
            'require_positive_base' => true,
        ],
        'ore_vein' => [
            'chain_interval' => 10,
            'high_grade_unlock_danger' => 200,
        ],
        'public_log' => [
            'minimum_danger' => 100,
        ],
    ],
];
