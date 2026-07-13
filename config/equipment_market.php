<?php

return [
    'listing_hours' => 72,
    'max_active_listings' => 10,
    'fee_rate_bps' => 1000,
    'minimum_price_bps' => 5000,
    'maximum_price_bps' => 25000,
    'appraisal_version' => 2,
    'weapon_rank_values' => [
        'G' => 2000, 'F' => 5000, 'E' => 10000, 'D' => 20000, 'C' => 40000,
        'B' => 70000, 'A' => 100000, 'S' => 250000, 'SS' => 600000,
        'SSS' => 1200000, 'EPIC' => 2500000,
    ],
    'trait_appraisal_values' => [1 => 5000, 2 => 15000, 3 => 50000, 4 => 150000, 5 => 500000],
    'secondary_trait_rate_bps' => 6000,
    'quality_multipliers_bps' => ['normal' => 10000, 'good' => 11500, 'excellent' => 13500],
    'enhance_multipliers_bps' => [0 => 10000, 1 => 10300, 2 => 10600, 3 => 11000],
];
