<?php

// Frozen legacy policy fixture. Keep old choice-based claims covered after changing the default.
return [
    'version' => 1,
    'bottles' => ['participation' => 3, 'stage10' => 3, 'completion' => 2],
    'completion_free_kiseki' => 5,
    'damage_thresholds' => ['damage250k' => 250_000, 'damage1m' => 1_000_000, 'damage2m' => 2_000_000],
    'fragment_quantities' => ['damage250k' => 3, 'damage1m' => 5],
    'talisman_quantity' => 1,
    'nation_thresholds' => [250_000 => 1_000, 750_000 => 3_000, 1_500_000 => 6_000],
    'nation_reference_cap' => 1_000,
    'nation_qualified_cap' => 1_500,
];
