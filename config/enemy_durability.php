<?php

return [
    // 全体有効化スイッチ。falseで全倍率が1.0(無効)になる。
    // デフォルトfalse: 武器・銘の本番反映状況と、上位進化素材の入手経路が
    // 未整備なため、都市9以降のSS以上装備を前提にした耐久補正は時期尚早。
    // 有効化する際は 本ファイルの各tierのenabledと合わせて個別にtrueへ切り替える。
    'enabled' => env('ENEMY_DURABILITY_ENABLED', false),

    // 敵の役割(role_key)がここに含まれる場合、都市帯に関わらず一切の補正を適用しない。
    // 転職直後の育て直し・弱者救済枠として常に現行値を維持する。
    'excluded_roles' => [
        'normal_weak',
        'deep_candidate',
        'golden',
    ],

    // 個別に補正対象から除外する敵ID。都市10には normal_weak が存在しないため、
    // 最もHPが低い通常敵(id=409 魔導炉の魔物)を転職直後の安全枠として明示的に除外する。
    'safe_enemy_ids' => [
        409, // 魔導炉の魔物（都市10・normal中最弱）
    ],

    // 隠し超ボス判定基準。is_boss かつ level >= この値の敵は、
    // 所属都市(4/7/8/9等)の通常ボス倍率ではなく super_boss 倍率を優先して適用する。
    'super_boss_level_threshold' => 200,

    // 都市帯ごとの設定。city1〜7・星樹の塔は本config自体を参照しないため変更対象外。
    'tiers' => [
        'city8' => [
            'enabled' => env('ENEMY_DURABILITY_CITY8_ENABLED', false),
            'roles' => [
                'normal' => ['hp' => 1.08, 'def_spr' => 1.00, 'atk_mag' => 1.00],
                'rare'   => ['hp' => 1.05, 'def_spr' => 1.00, 'atk_mag' => 1.00],
                'strong' => ['hp' => 1.08, 'def_spr' => 1.10, 'atk_mag' => 1.00],
                'boss'   => ['hp' => 1.08, 'def_spr' => 1.15, 'atk_mag' => 1.00],
            ],
        ],
        'city9' => [
            'enabled' => env('ENEMY_DURABILITY_CITY9_ENABLED', false),
            'roles' => [
                'normal' => ['hp' => 1.10, 'def_spr' => 1.05, 'atk_mag' => 1.00],
                'rare'   => ['hp' => 1.08, 'def_spr' => 1.05, 'atk_mag' => 1.00],
                'strong' => ['hp' => 1.10, 'def_spr' => 1.15, 'atk_mag' => 1.00],
                'boss'   => ['hp' => 1.10, 'def_spr' => 1.20, 'atk_mag' => 1.05],
            ],
        ],
        'city10' => [
            'enabled' => env('ENEMY_DURABILITY_CITY10_ENABLED', false),
            'roles' => [
                'normal' => ['hp' => 1.12, 'def_spr' => 1.08, 'atk_mag' => 1.00],
                'rare'   => ['hp' => 1.10, 'def_spr' => 1.08, 'atk_mag' => 1.00],
                'strong' => ['hp' => 1.10, 'def_spr' => 1.20, 'atk_mag' => 1.00],
                'boss'   => ['hp' => 1.12, 'def_spr' => 1.25, 'atk_mag' => 1.05],
            ],
        ],
        // 秘境(フェルディア/city_id 101-103)。通常・レア・強敵と、ボス(別数値)を分ける。
        'hikyo' => [
            'enabled' => env('ENEMY_DURABILITY_HIKYO_ENABLED', false),
            'city_ids' => [101, 102, 103],
            'roles' => [
                'normal' => ['hp' => 1.12, 'def_spr' => 1.10, 'atk_mag' => 1.00],
                'rare'   => ['hp' => 1.12, 'def_spr' => 1.10, 'atk_mag' => 1.00],
                'strong' => ['hp' => 1.15, 'def_spr' => 1.25, 'atk_mag' => 1.00],
                // 秘境ボス: 素EPICが「挑戦として成立」する強度まで緩和した最終値。
                // 本番観測で下げが必要な場合は def_spr → hp → atk_mag の順に1段階ずつ緩和する
                // （詳細は「秘境ボスの調整条件」を参照。倍率のみ変更し、種族特攻率は変更しない）。
                'boss'   => ['hp' => 1.10, 'def_spr' => 1.20, 'atk_mag' => 1.05],
            ],
        ],
        // 隠し超ボス(Lv200以上、is_boss=true)。都市帯のボス倍率より優先して適用する。
        'super_boss' => [
            'enabled' => env('ENEMY_DURABILITY_SUPER_BOSS_ENABLED', false),
            'roles' => [
                'boss' => ['hp' => 1.10, 'def_spr' => 1.15, 'atk_mag' => 1.00],
            ],
        ],
    ],

    // エリア画面表示・装備比較用の推奨武器ランク。
    // progress = そのエリアへ進行するための目安、stable = 安定周回するための目安。
    // status='available'  : 通常どおり色分け表示する。
    // status='preparing'  : そのランクへ至る上位進化素材の入手経路が未整備のため、
    //                        「準備中」表示のみとし、緑/黄/赤の色分け判定は行わない。
    //                        S以上の素材入手経路が整い次第 'available' へ切り替える。
    'weapon_rank_guidance' => [
        1 => ['progress' => 'G', 'stable' => 'G', 'status' => 'available'],
        2 => ['progress' => 'F', 'stable' => 'F', 'status' => 'available'],
        3 => ['progress' => 'E', 'stable' => 'E', 'status' => 'available'],
        4 => ['progress' => 'D', 'stable' => 'D', 'status' => 'available'],
        5 => ['progress' => 'C', 'stable' => 'C', 'status' => 'available'],
        6 => ['progress' => 'B', 'stable' => 'B', 'status' => 'available'],
        7 => ['progress' => 'A', 'stable' => 'A', 'status' => 'available'],
        8 => ['progress' => 'S', 'stable' => 'S', 'status' => 'preparing'],
        9 => ['progress' => 'SS', 'stable' => 'SS', 'status' => 'preparing'],
        10 => ['progress' => 'SS', 'stable' => 'SSS', 'status' => 'preparing'],
        101 => ['progress' => 'SSS', 'stable' => 'EPIC', 'status' => 'preparing'],
        102 => ['progress' => 'SSS', 'stable' => 'EPIC', 'status' => 'preparing'],
        103 => ['progress' => 'SSS', 'stable' => 'EPIC', 'status' => 'preparing'],
    ],
];
