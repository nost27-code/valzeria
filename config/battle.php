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
        'dynamic_single' => env('BATTLE_JOB_ART_DYNAMIC_SINGLE', false),
        'normalized_sp' => env('BATTLE_JOB_ART_NORMALIZED_SP', false),
        'hit_resolution' => env('BATTLE_JOB_ART_HIT_RESOLUTION', false),
        'damage_application' => env('BATTLE_JOB_ART_DAMAGE_APPLICATION', false),
        'resources' => env('BATTLE_JOB_ART_RESOURCES', false),
        'fields' => env('BATTLE_JOB_ART_FIELDS', false),
        'penetration' => env('BATTLE_JOB_ART_PENETRATION', false),
        'penetration_stance' => env('BATTLE_JOB_ART_PENETRATION_STANCE', false),
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
