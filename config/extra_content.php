<?php

return [
    'contents' => [
        'star_tree_tower' => [
            'name' => '星樹の塔',
            'category' => 'エクスト塔',
            'description' => '精霊の森エルフィアに出現した、1階ずつ登る期間公開向けの塔コンテンツです。',
            'route' => 'tower.star-tree.index',
            'default_enabled' => env('STAR_TREE_TOWER_ENABLED', false),
            'setting_label' => '星樹の塔 公開状態',
        ],
        'ferdia_unlocked' => [
            'name' => 'フェルディア大陸',
            'category' => '新地方',
            'description' => '外大陸フェルディア大陸のMAPタブと探索導線を公開します。OFF中は移動画面から見えません。',
            'route' => 'city.index',
            'default_enabled' => env('FERDIA_REGION_ENABLED', false),
            'setting_label' => 'フェルディア大陸 公開状態',
        ],
        'exploration_support' => [
            'name' => '探索補助品',
            'category' => '追加機能',
            'description' => '薬屋、探索補助品、もちもの導線を公開します。OFF中は画面と直URLの両方から利用できません。',
            'route' => 'apothecary.index',
            'default_enabled' => env('EXPLORATION_SUPPORT_ENABLED', false),
            'setting_label' => '探索補助品 公開状態',
        ],
    ],
];
