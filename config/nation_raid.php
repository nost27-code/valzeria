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
        // 出撃回数の上限なし。日次カウンタは投票・参加条件・監査のため保持する。
        'sortie_stamina_cost' => 10,
        'resolution_grace_minutes' => 10,
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
