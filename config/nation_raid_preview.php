<?php

// Display-only release snapshot, exported from the approved local candidate catalog.
// Never use this file to grant rewards. Refresh and review it when planned rewards change.
return [
    'source_policy_hash' => 'b0a4b87e859f5737da207f8630f0712f73cf2f4a6ff8802a4abf8b02d1144e4b',
    'boss_name' => '十系喰らいの黒天竜 ヴァルグレイド',
    'boss_image' => 'images/raid/valgreid_form_01.webp',
    'minimum_sorties' => 15,
    'participation_minimum_sorties' => 5,
    'groups' => [
        'participation' => [
            'label' => '参加報酬',
            'rows' => [
                [
                    'key' => 'participation',
                    'display_label' => '参加報酬',
                    'condition' => '有効出撃5回',
                    'items' => [
                        [
                            'label' => '探索力の小瓶 ×1',
                            'icon' => 'images/icon/icon_088.webp',
                        ],
                    ],
                ],
            ],
        ],
        'damage' => [
            'label' => '個人ダメージ報酬',
            'rows' => [
                [
                    'key' => 'milestone_10000',
                    'display_label' => '1万ダメージ',
                    'condition' => '個人累計10,000ダメージ・有効出撃15回',
                    'items' => [
                        [
                            'label' => '強化石の欠片 ×1',
                            'icon' => 'images/icon/icon_094.webp',
                        ],
                        [
                            'label' => '無償輝石 ×3',
                            'icon' => 'images/icon/kiseki.webp',
                        ],
                    ],
                ],
                [
                    'key' => 'milestone_50000',
                    'display_label' => '5万ダメージ',
                    'condition' => '個人累計50,000ダメージ・有効出撃15回',
                    'items' => [
                        [
                            'label' => '守護石の欠片 ×1',
                            'icon' => 'images/icon/icon_097.webp',
                        ],
                        [
                            'label' => '無償輝石 ×3',
                            'icon' => 'images/icon/kiseki.webp',
                        ],
                    ],
                ],
                [
                    'key' => 'milestone_100000',
                    'display_label' => '10万ダメージ',
                    'condition' => '個人累計100,000ダメージ・有効出撃15回',
                    'items' => [
                        [
                            'label' => '調律石の欠片 ×1',
                            'icon' => 'images/icon/icon_100.webp',
                        ],
                        [
                            'label' => '探索力の小瓶 ×1',
                            'icon' => 'images/icon/icon_088.webp',
                        ],
                        [
                            'label' => '無償輝石 ×3',
                            'icon' => 'images/icon/kiseki.webp',
                        ],
                    ],
                ],
                [
                    'key' => 'milestone_250000',
                    'display_label' => '25万ダメージ',
                    'condition' => '個人累計250,000ダメージ・有効出撃15回',
                    'items' => [
                        [
                            'label' => '強化石の欠片 ×2',
                            'icon' => 'images/icon/icon_094.webp',
                        ],
                        [
                            'label' => '無償輝石 ×3',
                            'icon' => 'images/icon/kiseki.webp',
                        ],
                        [
                            'label' => '経験の護符 ×1（Lv255では使用不可）',
                            'icon' => 'images/icon/icon_014.webp',
                        ],
                    ],
                ],
                [
                    'key' => 'milestone_500000',
                    'display_label' => '50万ダメージ',
                    'condition' => '個人累計500,000ダメージ・有効出撃15回',
                    'items' => [
                        [
                            'label' => '守護石の欠片 ×2',
                            'icon' => 'images/icon/icon_097.webp',
                        ],
                        [
                            'label' => '無償輝石 ×3',
                            'icon' => 'images/icon/kiseki.webp',
                        ],
                    ],
                ],
                [
                    'key' => 'milestone_750000',
                    'display_label' => '75万ダメージ',
                    'condition' => '個人累計750,000ダメージ・有効出撃15回',
                    'items' => [
                        [
                            'label' => '調律石の欠片 ×2',
                            'icon' => 'images/icon/icon_100.webp',
                        ],
                        [
                            'label' => '無償輝石 ×3',
                            'icon' => 'images/icon/kiseki.webp',
                        ],
                    ],
                ],
                [
                    'key' => 'milestone_1000000',
                    'display_label' => '100万ダメージ',
                    'condition' => '個人累計1,000,000ダメージ・有効出撃15回',
                    'items' => [
                        [
                            'label' => '強化石の欠片 ×3',
                            'icon' => 'images/icon/icon_094.webp',
                        ],
                        [
                            'label' => '探索力の小瓶 ×1',
                            'icon' => 'images/icon/icon_088.webp',
                        ],
                        [
                            'label' => '無償輝石 ×3',
                            'icon' => 'images/icon/kiseki.webp',
                        ],
                        [
                            'label' => '経験の護符 ×1（Lv255では使用不可）',
                            'icon' => 'images/icon/icon_014.webp',
                        ],
                    ],
                ],
                [
                    'key' => 'milestone_2000000',
                    'display_label' => '200万ダメージ',
                    'condition' => '個人累計2,000,000ダメージ・有効出撃15回',
                    'items' => [
                        [
                            'label' => '守護石の欠片 ×3',
                            'icon' => 'images/icon/icon_097.webp',
                        ],
                        [
                            'label' => '無償輝石 ×3',
                            'icon' => 'images/icon/kiseki.webp',
                        ],
                    ],
                ],
                [
                    'key' => 'milestone_5000000',
                    'display_label' => '500万ダメージ',
                    'condition' => '個人累計5,000,000ダメージ・有効出撃15回',
                    'items' => [
                        [
                            'label' => '調律石の欠片 ×3',
                            'icon' => 'images/icon/icon_100.webp',
                        ],
                        [
                            'label' => '探索力の小瓶 ×3',
                            'icon' => 'images/icon/icon_088.webp',
                        ],
                        [
                            'label' => '無償輝石 ×3',
                            'icon' => 'images/icon/kiseki.webp',
                        ],
                    ],
                ],
            ],
        ],
        'server' => [
            'label' => '全体討伐報酬',
            'rows' => [
                [
                    'key' => 'stage10',
                    'display_label' => '第10再臨到達報酬',
                    'condition' => '全体で第10再臨に到達・有効出撃15回',
                    'items' => [
                        [
                            'label' => '探索力の小瓶 ×3',
                            'icon' => 'images/icon/icon_088.webp',
                        ],
                    ],
                ],
                [
                    'key' => 'completion',
                    'display_label' => '黒天竜討伐報酬',
                    'condition' => '全体で第20再臨を撃破・有効出撃15回',
                    'items' => [
                        [
                            'label' => '探索力の小瓶 ×2',
                            'icon' => 'images/icon/icon_088.webp',
                        ],
                        [
                            'label' => '無償輝石 ×5',
                            'icon' => 'images/icon/kiseki.webp',
                        ],
                    ],
                ],
            ],
        ],
        'honor' => [
            'label' => '称号・順位報酬',
            'rows' => [
                [
                    'key' => 'damage2m',
                    'display_label' => '200万ダメージの称号',
                    'condition' => '個人累計2,000,000ダメージ・有効出撃15回',
                    'items' => [
                        [
                            'label' => '称号「黒天竜を穿つ者」（能力補正なし）',
                            'icon' => 'images/icon/icon_009.webp',
                        ],
                    ],
                ],
                [
                    'key' => 'personal_first',
                    'display_label' => '万軍の先鋒',
                    'condition' => '個人累計ダメージ1位・有効出撃15回',
                    'items' => [
                        [
                            'label' => '称号「万軍の先鋒」（能力補正なし）',
                            'icon' => 'images/icon/icon_009.webp',
                        ],
                        [
                            'label' => '冒険者カード記章',
                            'icon' => 'images/icon/icon_009.webp',
                        ],
                    ],
                ],
                [
                    'key' => 'personal_top3',
                    'display_label' => '黒天竜討滅の功臣',
                    'condition' => '個人累計ダメージ2〜3位・有効出撃15回',
                    'items' => [
                        [
                            'label' => '称号「黒天竜討滅の功臣」（能力補正なし）',
                            'icon' => 'images/icon/icon_009.webp',
                        ],
                    ],
                ],
                [
                    'key' => 'max_first',
                    'display_label' => '天穿の一撃',
                    'condition' => '1行動最大ダメージ1位・有効出撃15回',
                    'items' => [
                        [
                            'label' => '称号「天穿の一撃」（能力補正なし）',
                            'icon' => 'images/icon/icon_009.webp',
                        ],
                        [
                            'label' => '冒険者カード記章',
                            'icon' => 'images/icon/icon_009.webp',
                        ],
                    ],
                ],
            ],
        ],
    ],
];
