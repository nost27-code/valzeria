<?php

return [
    /*
    |--------------------------------------------------------------------------
    | PvE enemy direct-damage defense formula
    |--------------------------------------------------------------------------
    |
    | Applies only when an enemy directly attacks a player in PvE. Keeping this
    | disabled preserves the existing subtractive calculation exactly.
    |
    */
    'pve_enemy_percentage_defense' => [
        'enabled' => env('PVE_ENEMY_PERCENTAGE_DEFENSE_ENABLED', false),
        'defense_coefficient' => (float) env('PVE_ENEMY_DEFENSE_COEFFICIENT', 0.8),
    ],
];
