<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Equipment proficiency penalty
    |--------------------------------------------------------------------------
    |
    | When disabled, weapon and armor categories keep the current strict
    | equip restrictions. When enabled, every job may equip them, while a
    | non-proficient category only provides the configured share of its total
    | equipment effects (base, enhancement, engraving, slayer, and resistance).
    | Weapons use their category rate so highly specialized weapon types keep
    | more of their performance outside their native jobs. Armor uses the
    | shared fallback rate.
    |
    */
    'non_proficient' => [
        'enabled' => env('EQUIPMENT_PROFICIENCY_PENALTY_ENABLED', false),
        'effect_rate' => (float) env('EQUIPMENT_NON_PROFICIENT_EFFECT_RATE', 0.65),
        'weapon_effect_rates' => [
            'fist' => 0.85,
            'katana' => 0.85,
            'bow' => 0.75,
            'axe' => 0.75,
            'dagger' => 0.75,
            'spear' => 0.70,
            'sword' => 0.65,
            'gun' => 0.65,
            'staff' => 0.65,
            'magic_device' => 0.65,
        ],
    ],
];
