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
    ],
];
