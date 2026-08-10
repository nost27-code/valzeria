<?php

$allowedCharacterIds = array_values(array_unique(array_filter(
    array_map(
        static function (string $value): int {
            $value = trim($value);

            return ctype_digit($value) ? (int) $value : 0;
        },
        explode(',', (string) env('WEB_PUSH_ALLOWED_CHARACTER_IDS', ''))
    ),
    static fn (int $characterId): bool => $characterId > 0
)));

return [
    'mode' => strtolower(trim((string) env('WEB_PUSH_MODE', 'off'))),
    'allowed_character_ids' => $allowedCharacterIds,
    'preview_mode' => strtolower(trim((string) env('WEB_PUSH_PREVIEW_MODE', 'generic'))),

    'vapid' => [
        'subject' => trim((string) env('WEB_PUSH_VAPID_SUBJECT', env('APP_URL', ''))),
        'public_key' => trim((string) env('WEB_PUSH_VAPID_PUBLIC_KEY', '')),
        'private_key' => trim((string) env('WEB_PUSH_VAPID_PRIVATE_KEY', '')),
    ],

    'ttl' => max(0, (int) env('WEB_PUSH_TTL', 3600)),
    'batch_size' => max(1, min(100, (int) env('WEB_PUSH_BATCH_SIZE', 20))),

    'notification_types' => [
        'adventure' => [
            'label' => '冒険',
            'items' => [
                'exploration_stamina_full' => [
                    'label' => '探索力がMAXになったとき',
                    'description' => '時間経過で探索力が最大まで回復したときに知らせます。',
                    'types' => ['exploration_stamina_full'],
                    'default' => true,
                ],
                'arena_rank_down' => [
                    'label' => '闘技場の順位が下がったとき',
                    'description' => 'ほかの冒険者に抜かれて順位が下がったときに知らせます。',
                    'types' => ['arena_rank_down'],
                    'default' => true,
                ],
                'weekly_win_ranking_reward' => [
                    'label' => '週間勝利ランキングの報酬',
                    'description' => '週間勝利ランキングの報酬が届いたときに知らせます。',
                    'types' => ['weekly_win_ranking_reward'],
                    'default' => true,
                ],
            ],
        ],
        'market' => [
            'label' => '市場・商店',
            'items' => [
                'market_material_sold' => [
                    'label' => '素材市場で売れたとき',
                    'description' => '出品した素材が購入されたときに知らせます。',
                    'types' => ['market_material_sold'],
                    'default' => true,
                ],
                'equipment_market_sold' => [
                    'label' => '装備市場で売れたとき',
                    'description' => '出品した装備が購入されたときに知らせます。',
                    'types' => ['equipment_market_sold'],
                    'default' => true,
                ],
                'shop_egg_sold' => [
                    'label' => 'プレイヤー商店で卵が売れたとき',
                    'description' => '出品したヴァルモンの卵が購入されたときに知らせます。',
                    'types' => ['shop_egg_sold'],
                    'default' => true,
                ],
                'equipment_market_expired' => [
                    'label' => '装備市場の出品期限が切れたとき',
                    'description' => '装備の出品期限が切れて返却されたときに知らせます。',
                    'types' => ['equipment_market_expired'],
                    'default' => true,
                ],
                'equipment_market_purchased' => [
                    'label' => '装備市場で購入したとき',
                    'description' => '装備市場での購入結果を知らせます。',
                    'types' => ['equipment_market_purchased'],
                    'default' => true,
                ],
            ],
        ],
        'communication' => [
            'label' => '交流・運営',
            'items' => [
                'private_message' => [
                    'label' => '個別メッセージが届いたとき',
                    'description' => '冒険者や運営からの個別メッセージ、キャラアイコン作成の連絡を知らせます。',
                    'types' => [
                        'private_message',
                        'admin_private_message',
                        'character_icon_design_status',
                        'character_icon_design_message',
                    ],
                    'default' => true,
                ],
                'admin_grants' => [
                    'label' => '運営からの配布・補填',
                    'description' => 'ゴールド、アイテム、キセキなどが届いたときに知らせます。',
                    'types' => [
                        'admin_paid_kiseki_grant',
                        'admin_gold_grant',
                        'admin_item_grant',
                        'admin_global_compensation',
                        'area7_monster_mark_compensation',
                    ],
                    'default' => true,
                ],
                'registration_campaign' => [
                    'label' => '登録キャンペーンの贈り物',
                    'description' => '新規登録キャンペーンの贈り物が届いたときに知らせます。',
                    'types' => ['newcomer_stamina_bottle_gift', 'newcomer_stamina_potion_bonus_gift'],
                    'default' => true,
                ],
                'official_updates' => [
                    'label' => '公式からのお知らせ',
                    'description' => '更新情報など、公式のお知らせが届いたときに知らせます。',
                    'types' => ['note_rss_update'],
                    'default' => true,
                ],
                'other' => [
                    'label' => 'その他の新しい通知',
                    'description' => '今後追加される通知など、上の項目に当てはまらないものを知らせます。',
                    'types' => [],
                    'matches_unmapped' => true,
                    'default' => true,
                ],
            ],
        ],
    ],
];
