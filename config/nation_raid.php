<?php

return [
    // 新規出撃の作戦選択・候補順補正は停止。通常のボス戦セット設定を使う。
    'strategy_enabled' => env('NATION_RAID_STRATEGY_ENABLED', false),

    /* ローカル試遊でだけ使う、3時間国家連携の期限付き保存先。 */
    'trial_coordination_cache_store' => env('NATION_RAID_TRIAL_CACHE_STORE', 'file'),

    // 本番eventの公開値ではなく、Phase 3以降が検証するdomain契約。
    // balance未承認のeventはService層でscheduled/activeへ進めない。
    'event' => [
        'announcement_lead_hours' => 72,
        'duration_hours' => 168,
        'active_window_days' => 7,
        // 無料枠を使い切った後も探索力で自主出撃できる。開催ごとにrulesetへ固定する。
        'sortie_stamina_cost' => 10,
        'resolution_grace_minutes' => 10,
        'automatic_finalization_delay_minutes' => 30,
    ],

    'free_sorties' => [
        'daily_grant' => 3,
        'balance_cap' => 9,
        'readiness_daily_grant_threshold_percent' => 50,
        'readiness_daily_grant' => 4,
        'readiness_balance_cap_threshold_percent' => 80,
        'readiness_balance_cap' => 12,
    ],

    'logistics_preparation' => [
        'duration_hours' => 72,
        'active_window_days' => 7,
        'required_contributions_per_active_member' => 2,
        'max_contributions_per_member' => 3,
        'readiness_full_percent' => 100,
    ],

    'outcome' => [
        'repelled_threshold_percent' => 80,
        'invasion_damage' => [
            ['minimum_progress_percent' => 60, 'damage' => 20],
            ['minimum_progress_percent' => 30, 'damage' => 40],
            ['minimum_progress_percent' => 0, 'damage' => 60],
        ],
        'readiness_mitigation' => [80 => 5, 100 => 10],
        'participation_mitigation' => [50 => 5, 75 => 10],
        'minimum_invasion_damage' => 10,
        'effective_participation_sorties' => 5,
        'reconstruction_damage_divisor' => 10,
        'reconstruction_daily_cap' => 3,
    ],

    // 既存提案仕様の有効参加条件。報酬供給の公開承認は別途必要。
    'qualification' => ['minimum_resolved_sorties' => 15],

    'settlement' => [
        'attempts' => 3,
        'backoff_milliseconds' => [50, 150],
        'jitter_max_milliseconds' => 50,
        'innodb_lock_wait_timeout_seconds' => 3,
    ],
];
