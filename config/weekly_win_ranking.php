<?php

return [
    'timezone' => 'Asia/Tokyo',

    // 日本時間の月曜9:00から翌月曜9:00未満を1週間として集計する。
    'period_start_hour' => 9,

    // 集計終了後に前週分を確定する時刻。
    'finalize_time' => '09:05',

    // 本番で初めて集計・報酬対象にするシーズンの開始日時。
    'first_eligible_period_start_at' => '2026-07-27 09:00:00',

    // 同率50位は全員を掲載・入賞対象にする。
    'ranking_limit' => 50,

    // 全タブ共通表示から同じ集計を繰り返さないための短時間キャッシュ。
    'live_cache_seconds' => 30,

    // 51位以下の参加賞に必要な週間勝利数。
    'minimum_participation_wins' => 10,

    /*
    |--------------------------------------------------------------------------
    | 週間勝利数番付の報酬
    |--------------------------------------------------------------------------
    |
    | 上から順に最初に一致した段階を採用する。参加賞は上位報酬へ加算しない。
    | badge_* は能力を持たない、翌週の冒険者カード表示用の名誉表示。
    |
    */
    'reward_tiers' => [
        [
            'key' => 'rank_1',
            'label' => '1位',
            'min_rank' => 1,
            'max_rank' => 1,
            'minimum_wins' => 1,
            'free_kiseki' => 20,
            'badge_key' => 'weekly_valor_first',
            'badge_label' => '今週の武勇一番',
        ],
        [
            'key' => 'rank_2',
            'label' => '2位',
            'min_rank' => 2,
            'max_rank' => 2,
            'minimum_wins' => 1,
            'free_kiseki' => 15,
            'badge_key' => 'weekly_valor_three',
            'badge_label' => '今週の武勇三傑',
        ],
        [
            'key' => 'rank_3',
            'label' => '3位',
            'min_rank' => 3,
            'max_rank' => 3,
            'minimum_wins' => 1,
            'free_kiseki' => 10,
            'badge_key' => 'weekly_valor_three',
            'badge_label' => '今週の武勇三傑',
        ],
        [
            'key' => 'rank_4_10',
            'label' => '4〜10位',
            'min_rank' => 4,
            'max_rank' => 10,
            'minimum_wins' => 1,
            'free_kiseki' => 8,
            'badge_key' => 'weekly_valor_ten',
            'badge_label' => '今週の武勇十傑',
        ],
        [
            'key' => 'rank_11_20',
            'label' => '11〜20位',
            'min_rank' => 11,
            'max_rank' => 20,
            'minimum_wins' => 1,
            'free_kiseki' => 5,
            'badge_key' => null,
            'badge_label' => null,
        ],
        [
            'key' => 'rank_21_30',
            'label' => '21〜30位',
            'min_rank' => 21,
            'max_rank' => 30,
            'minimum_wins' => 1,
            'free_kiseki' => 3,
            'badge_key' => null,
            'badge_label' => null,
        ],
        [
            'key' => 'rank_31_50',
            'label' => '31〜50位',
            'min_rank' => 31,
            'max_rank' => 50,
            'minimum_wins' => 1,
            'free_kiseki' => 2,
            'badge_key' => null,
            'badge_label' => null,
        ],
        [
            'key' => 'participation',
            'label' => '参加賞',
            'min_rank' => 51,
            'max_rank' => null,
            'minimum_wins' => 10,
            'free_kiseki' => 1,
            'badge_key' => null,
            'badge_label' => null,
        ],
    ],
];
