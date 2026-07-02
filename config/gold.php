<?php

return [
    // Equipment sale runtime source of truth is items.sell_price.
    // This rank table is only a baseline for initial data/migration setup.
    'equipment_sell_prices' => [
        'G' => 100,
        'F' => 150,
        'E' => 220,
        'D' => 320,
        'C' => 450,
        'B' => 650,
        'A' => 900,
        'S' => 1000,
        'SS' => 0,
        'SSS' => 0,
    ],

    'evolution_costs' => [
        'G' => 0,
        'F' => 100,
        'E' => 200,
        'D' => 300,
        'C' => 500,
        'B' => 1000,
        'A' => 2000,
        'S' => 4000,
        'SS' => 8000,
        'SSS' => 15000,
        'EPIC' => 30000,
    ],

    'battle' => [
        'normal_drop_rate' => 5,
        'boss_drop_rate' => 12,
    ],
];
