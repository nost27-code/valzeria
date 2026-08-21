<?php

return [
    'operations' => [
        'expected_database_product' => strtolower((string) env(
            'SIX_HERO_EXPECTED_DATABASE_PRODUCT',
            'mysql',
        )),
        'minimum_database_version' => (string) env(
            'SIX_HERO_MINIMUM_DATABASE_VERSION',
            '8.4.0',
        ),
        'stale_battle_minutes' => max(1, (int) env(
            'SIX_HERO_STALE_BATTLE_MINUTES',
            30,
        )),
        'failed_battle_window_hours' => max(1, (int) env(
            'SIX_HERO_FAILED_BATTLE_WINDOW_HOURS',
            24,
        )),
        'battle_list_limit' => min(100, max(1, (int) env(
            'SIX_HERO_OPERATIONS_BATTLE_LIST_LIMIT',
            20,
        ))),
    ],
];
