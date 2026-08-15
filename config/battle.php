<?php

return [
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
