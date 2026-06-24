<?php

return [
    'items' => [
        'material_storage_expand' => [
            'name' => '素材倉庫拡張',
            'category' => '保管拡張',
            'price' => 100,
            'description' => '素材の保管枠を+50します。進化素材や秘境素材を多く保管したい冒険者向けです。',
            'effect_value' => 50,
            'purchase_limit' => 10,
        ],
        'equipment_storage_expand' => [
            'name' => '装備倉庫拡張',
            'category' => '保管拡張',
            'price' => 80,
            'description' => '武器・防具・装飾品の保管枠を+50します。分岐装備やレア装備を集めたい冒険者向けです。',
            'effect_value' => 50,
            'purchase_limit' => 10,
        ],
        'adventurer_supply_box' => [
            'name' => '冒険者補給箱',
            'category' => '探索支援',
            'price' => 50,
            'description' => '薬草・回復薬・魔力水を各10個補充します。探索に持ち込める数は、これまで通り各10個までです。',
            'daily_purchase_limit' => 1,
        ],
        'rescue_insurance' => [
            'name' => '救助保険証',
            'category' => '救助',
            'price' => 10,
            'description' => '探索開始前に使うと、全滅時の入手品ロストを25%に抑えます。危険な連戦に挑む前の備えです。',
        ],
        'emergency_rescue_request' => [
            'name' => '緊急救助要請',
            'category' => '救助',
            'price' => 50,
            'description' => '全滅時に使用すると、今回の入手品ロストを0にします。ただし、探索は終了し、街へ帰還します。',
            'daily_use_limit' => 1,
        ],
    ],
];
