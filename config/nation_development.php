<?php

return [
    'max_level' => 50,
    'next_level_exp_multiplier' => 500,
    'system_absolute_member_cap' => 100,

    /*
     * 国家Lv特典のコード上の正本。
     * 表にないLvは直前の値を引き継ぐ。
     */
    'benefit_milestones' => [
        1 => [
            'member_capacity' => 20,
            'facility_level_cap' => 5,
            'goal_slots' => 1,
            'wanted_material_slots' => 1,
            'showcase_slots' => 0,
            'war_preset_slots' => 0,
            'features' => ['contribution_totals'],
            'label' => '共同目標1件・募集素材1種類',
        ],
        5 => [
            'member_capacity' => 22,
            'goal_slots' => 2,
            'features' => ['bronze_decorations'],
            'label' => '定員22人・共同目標2件・銅装飾',
        ],
        10 => [
            'member_capacity' => 24,
            'wanted_material_slots' => 2,
            'showcase_slots' => 1,
            'features' => ['achievement_showcase'],
            'label' => '定員24人・募集素材2種類・実績展示1枠',
        ],
        15 => [
            'member_capacity' => 26,
            'facility_level_cap' => 6,
            'goal_slots' => 3,
            'features' => ['silver_decorations', 'public_timeline'],
            'label' => '定員26人・施設Lv6・国家年表',
        ],
        20 => [
            'member_capacity' => 28,
            'facility_level_cap' => 7,
            'war_preset_slots' => 1,
            'features' => ['donation_period_analytics', 'war_preparation_presets'],
            'label' => '定員28人・施設Lv7・週間／月間納品集計',
        ],
        25 => [
            'member_capacity' => 30,
            'facility_level_cap' => 8,
            'wanted_material_slots' => 3,
            'showcase_slots' => 2,
            'label' => '定員30人・施設Lv8・募集素材3種類・実績展示2枠',
        ],
        30 => [
            'member_capacity' => 32,
            'facility_level_cap' => 9,
            'war_preset_slots' => 2,
            'label' => '定員32人・施設Lv9・戦争準備プリセット2件',
        ],
        35 => [
            'member_capacity' => 34,
            'facility_level_cap' => 10,
            'features' => ['donation_material_analytics', 'gold_decorations'],
            'label' => '定員34人・施設Lv10・素材別分析・金装飾',
        ],
        40 => [
            'member_capacity' => 36,
            'wanted_material_slots' => 5,
            'showcase_slots' => 3,
            'label' => '定員36人・募集素材5種類・実績展示3枠',
        ],
        45 => [
            'member_capacity' => 38,
            'features' => ['special_emblem_frame', 'special_name_plate'],
            'label' => '定員38人・特別紋章枠・国家名プレート',
        ],
        50 => [
            'member_capacity' => 40,
            'war_preset_slots' => 3,
            'features' => ['max_level_title', 'exclusive_header', 'exclusive_badge'],
            'label' => '定員40人・最大Lv称号・専用ヘッダ／徽章',
        ],
    ],
];
