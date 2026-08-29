<?php

return [
    'speed_breakthrough' => [
        'enabled' => env('PVP_SPEED_BREAKTHROUGH_ENABLED', false),
    ],

    'beginner_defeat_protection' => [
        'battle_limit' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job art v2 prototype switches
    |--------------------------------------------------------------------------
    */
    'job_art_v2' => [
        'presets' => env('BATTLE_JOB_ART_PRESETS', false),
        'preset_free_limit' => 3,
        'pvp_set' => env('BATTLE_JOB_ART_PVP_SET', false),
        'loadout_v2' => env('BATTLE_JOB_ART_LOADOUT_V2', false),
        // 候補順・奥義発動率を変える詳細戦術は、SP出力とは独立して公開する。
        'detailed_strategy' => env('BATTLE_JOB_ART_DETAILED_STRATEGY', false),
        // Keep the compact v2 cards now; the dormant per-card detail controls
        // can be restored without reviving their old runtime conditions.
        'loadout_card_details' => env('BATTLE_JOB_ART_LOADOUT_CARD_DETAILS', false),
        // 新戦技公開後の数日間だけ、公式プリセット導線を強調表示する。
        'official_preset_highlight_until' => env(
            'BATTLE_JOB_ART_OFFICIAL_PRESET_HIGHLIGHT_UNTIL',
            '2026-08-20 23:59:59',
        ),
        'dynamic_single' => env('BATTLE_JOB_ART_DYNAMIC_SINGLE', false),
        'normalized_sp' => env('BATTLE_JOB_ART_NORMALIZED_SP', false),
        'hit_resolution' => env('BATTLE_JOB_ART_HIT_RESOLUTION', false),
        'damage_application' => env('BATTLE_JOB_ART_DAMAGE_APPLICATION', false),
        'resources' => env('BATTLE_JOB_ART_RESOURCES', false),
        'fields' => env('BATTLE_JOB_ART_FIELDS', false),
        'penetration' => env('BATTLE_JOB_ART_PENETRATION', false),
        'penetration_stance' => env('BATTLE_JOB_ART_PENETRATION_STANCE', false),
        // Ten-lineage C-design release candidate. Default OFF until every
        // lineage passes the C-design RC gate.
        'c_design_prototype' => env('BATTLE_JOB_ART_C_DESIGN_PROTOTYPE', false),
        // PvP/Champの奥義予告・応答prototype。C-designとは別に既定OFF。
        'ultimate_counterplay' => env('BATTLE_JOB_ART_ULTIMATE_COUNTERPLAY', false),
        // 282奥義の台詞・攻撃描写rewrite。戦闘v2の他flagとは独立し、既定OFF。
        'flavor_rewrite' => env('BATTLE_JOB_ART_FLAVOR_REWRITE', false),
        'rank5_v6' => env('BATTLE_JOB_ART_RANK5_V6', false),
        'sp_power_scaling' => [
            // Rank5 v6.1を前提に校正したため、rank5_v6を含むcore flag鎖が
            // 1つでもOFFなら可変費・威力補正を適用しない。
            'enabled' => env('BATTLE_JOB_ART_SP_POWER_SCALING', false),
            // チャンプだけを緊急停止できるよう、主flagとは別の運用flagを維持する。
            'champ_enabled' => env('BATTLE_JOB_ART_SP_POWER_SCALING_CHAMP', false),
            'outputs' => [
                'none' => ['stage' => 0, 'cap_bps' => 0],
                'low' => ['stage' => 1, 'cap_bps' => 750],
                'standard' => ['stage' => 2, 'cap_bps' => 1_500],
                'high' => ['stage' => 3, 'cap_bps' => 2_250],
                'max' => ['stage' => 4, 'cap_bps' => 3_000],
            ],
            // 追加消費率（1bp = 最大SPの0.01%）。出力が高いほど
            // 威力1%あたりの追加消費が増える逓増表を採用する。
            'variable_cost_bps' => [
                1 => ['none' => 0, 'low' => 25, 'standard' => 75, 'high' => 150, 'max' => 250],
                5 => ['none' => 0, 'low' => 50, 'standard' => 150, 'high' => 300, 'max' => 500],
                9 => ['none' => 0, 'low' => 75, 'standard' => 225, 'high' => 450, 'max' => 750],
            ],
            'linear_limit' => 10_000,
            'linear_divisor' => 20,
            'excess_divisor' => 200,
            // 逓増消費表と組み合わせる非永続戦専用の可変費予算。
            'output_budget_percent' => 25,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | PvE enemy direct-damage defense formula
    |--------------------------------------------------------------------------
    |
    | Applies only when an enemy directly attacks a player in PvE. Set
    | PVE_ENEMY_PERCENTAGE_DEFENSE_ENABLED=false to temporarily use the
    | existing subtractive calculation.
    |
    */
    'pve_enemy_percentage_defense' => [
        'enabled' => env('PVE_ENEMY_PERCENTAGE_DEFENSE_ENABLED', true),
        'defense_coefficient' => (float) env('PVE_ENEMY_DEFENSE_COEFFICIENT', 3.5),
    ],
];
