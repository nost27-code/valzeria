<?php

return [
    'enabled' => env('SECURITY_ANOMALY_DETECTION_ENABLED', true),
    'retention_days' => 90,
    'exclude_admin_testers' => true,

    'rules' => [
        'rapid_battles' => [
            'window_minutes' => 10,
            'threshold' => 5_000,
        ],
        'gold_change' => [
            'window_minutes' => 15,
            'total_threshold' => 5_000_000,
            'single_threshold' => 3_000_000,
        ],
        'kiseki_change' => [
            'window_minutes' => 60,
            'total_threshold' => 1_000,
            'single_threshold' => 500,
        ],
        'job_exp' => [
            'max_per_reward' => 3,
            'window_hours' => 24,
        ],
        'shared_ip' => [
            'window_days' => 7,
            'account_threshold' => 5,
        ],
        'inventory_growth' => [
            'equipment_threshold' => 20,
            'material_threshold' => 500,
        ],
        'admin_grant_trade' => [
            'window_hours' => 24,
            'price_threshold' => 500_000,
        ],
    ],
];
