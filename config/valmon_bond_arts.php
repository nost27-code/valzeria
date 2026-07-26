<?php

return [
    'damage_variance_percent' => [
        'min' => 70,
        'max' => 130,
    ],

    'styles' => [
        'balanced' => [
            'label' => '均衡',
            'unlock_level' => 40,
            'rate' => 5.0,
            'power_rate' => 0.30,
            'description' => '発動率と威力のバランスを取った基本形。',
        ],
        'quick' => [
            'label' => '速攻',
            'unlock_level' => 60,
            'rate' => 6.0,
            'power_rate' => 0.25,
            'description' => '威力を抑え、発動しやすさを重視する。',
        ],
        'heavy' => [
            'label' => '豪撃',
            'unlock_level' => 80,
            'rate' => 3.0,
            'power_rate' => 0.50,
            'description' => '発動率を抑え、一撃の威力を重視する。',
        ],
    ],

    'phrase_styles' => [
        'trust' => [
            'label' => '信頼',
            'template' => '「任せたよ、{valmon}――一緒に決めよう！」',
        ],
        'hot_blooded' => [
            'label' => '熱血',
            'template' => '「行くぞ、{valmon}――一気に決めるぞ！」',
        ],
        'quiet' => [
            'label' => '静か',
            'template' => '「……今だ、{valmon}」',
        ],
        'cheerful' => [
            'label' => '元気',
            'template' => '「いっしょにいくよ、{valmon}！」',
        ],
    ],

    'fallback_technique' => [
        'names' => [
            'balanced' => '響き合う絆の一撃',
            'quick' => '迅き絆の連撃',
            'heavy' => '魂震わす絆の絶撃',
        ],
        'description' => '{valmon}が{target}へ駆け出し、冒険者との呼吸を重ねた！',
    ],

    'techniques' => [
        'rapil' => [
            'names' => [
                'balanced' => '蒼天・狐月連牙',
                'quick' => '蒼天・白狐連閃',
                'heavy' => '蒼天・天狐絶衝',
            ],
            'description' => '光の尾を引く{valmon}が、空を蹴って{target}へ飛び込んだ！',
        ],
        'pengle' => [
            'names' => [
                'balanced' => '氷海・蒼波突進',
                'quick' => '氷海・波乗連突',
                'heavy' => '氷海・大瀑氷砕',
            ],
            'description' => '冷気をまとった{valmon}が、砕ける波の勢いで突撃する！',
        ],
        'leafy' => [
            'names' => [
                'balanced' => '翠翼・葉嵐旋翔',
                'quick' => '翠翼・木葉連舞',
                'heavy' => '翠翼・大樹天墜',
            ],
            'description' => '舞い上がった木の葉が渦を巻き、{valmon}の翼とともに{target}を包む！',
        ],
        'dracol' => [
            'names' => [
                'balanced' => '機巧・轟鉄破',
                'quick' => '機巧・歯車連爪',
                'heavy' => '機巧・天衝轟砕',
            ],
            'description' => '歯車が唸りを上げ、{valmon}が鋼の一撃を叩き込む！',
        ],
        'gangoro' => [
            'names' => [
                'balanced' => '岩王・地脈拳',
                'quick' => '岩王・礫連拳',
                'heavy' => '岩王・城砕天拳',
            ],
            'description' => '大地を踏みしめた{valmon}の拳に、岩盤の力が集まった！',
        ],
        'sunamogu' => [
            'names' => [
                'balanced' => '月影・朧月爪',
                'quick' => '月影・黒猫瞬爪',
                'heavy' => '月影・天月葬爪',
            ],
            'description' => '月影へ溶けた{valmon}が、音もなく{target}の懐へ潜り込む！',
        ],
        'bolt_nya' => [
            'names' => [
                'balanced' => '宵闇・術喰牙',
                'quick' => '宵闇・蝙蝠連牙',
                'heavy' => '宵闇・黒翼葬牙',
            ],
            'description' => '黒い翼を広げた{valmon}が、闇を裂いて襲いかかる！',
        ],
        'kuropuru' => [
            'names' => [
                'balanced' => '蒼泡・流体崩し',
                'quick' => '蒼泡・水輪連弾',
                'heavy' => '蒼泡・大海嘯撃',
            ],
            'description' => '弾ける水泡がひとつに集まり、{valmon}の突進を加速させる！',
        ],
        'piyoram' => [
            'names' => [
                'balanced' => '煌針・蜜星乱舞',
                'quick' => '煌針・妖蜂連舞',
                'heavy' => '煌針・天花爆衝',
            ],
            'description' => 'きらめく花粉をまとい、{valmon}が流星のように駆ける！',
        ],
        'aquaron' => [
            'names' => [
                'balanced' => '導魂・冥灯送り',
                'quick' => '導魂・灯火連弾',
                'heavy' => '導魂・常夜葬送',
            ],
            'description' => '{valmon}のランタンが青白く燃え、迷える光が{target}を包み込む！',
        ],
        'morikoro' => [
            'names' => [
                'balanced' => '地縛・千根封陣',
                'quick' => '地縛・若根連打',
                'heavy' => '地縛・大樹葬界',
            ],
            'description' => '地中を走る無数の根が、{valmon}の合図で一斉に立ち上がる！',
        ],
        'koorisu' => [
            'names' => [
                'balanced' => '珊海・蒼潮角',
                'quick' => '珊海・潮角連撃',
                'heavy' => '珊海・海王大角衝',
            ],
            'description' => '潮騒が響き、{valmon}の珊瑚角に海の力が集まった！',
        ],
        'sabock' => [
            'names' => [
                'balanced' => '燐光・月蛾幻舞',
                'quick' => '燐光・妖羽連閃',
                'heavy' => '燐光・天照魔祓羽',
            ],
            'description' => '光る鱗粉が夜空のように広がり、{valmon}が幻想の軌跡を描く！',
        ],
        'rockam' => [
            'names' => [
                'balanced' => '穿鋼・地砕衝',
                'quick' => '穿鋼・地走連爪',
                'heavy' => '穿鋼・大地貫通牙',
            ],
            'description' => '地面を割って現れた{valmon}が、鋼の兜ごと突き上げる！',
        ],
        'lumi_cube' => [
            'names' => [
                'balanced' => '氷牙・月狼閃',
                'quick' => '氷牙・白狼連閃',
                'heavy' => '氷牙・氷獄絶衝',
            ],
            'description' => '白銀の軌跡を残し、{valmon}が凍てつく牙で駆け抜ける！',
        ],
        'nekmol' => [
            'names' => [
                'balanced' => '砂迅・蜃気楼牙',
                'quick' => '砂迅・流砂連爪',
                'heavy' => '砂迅・砂海大裂断',
            ],
            'description' => '砂煙へ姿を消した{valmon}が、死角から鋭く飛び出した！',
        ],
        'tsubasaur' => [
            'names' => [
                'balanced' => '時翼・刻界断ち',
                'quick' => '時翼・秒針連閃',
                'heavy' => '時翼・終刻天輪',
            ],
            'description' => '歯車羽が時を刻み、{valmon}の一撃だけが加速する！',
        ],
        'shellx' => [
            'names' => [
                'balanced' => '紫焔・夢喰葬',
                'quick' => '紫焔・夢喰連牙',
                'heavy' => '紫焔・冥獄魔葬',
            ],
            'description' => '紫炎をまとった{valmon}が、悪夢のごとく{target}へ迫る！',
        ],
        'miramy' => [
            'names' => [
                'balanced' => '聖角・天光穿',
                'quick' => '聖角・天光連閃',
                'heavy' => '聖角・星天浄化衝',
            ],
            'description' => '清らかな光が{valmon}の角へ集まり、一直線に闇を貫く！',
        ],
        'abysslim' => [
            'names' => [
                'balanced' => '紅蓮・竜星墜',
                'quick' => '紅蓮・幼竜連牙',
                'heavy' => '紅蓮・覇竜天墜',
            ],
            'description' => '灼熱の咆哮とともに、{valmon}が紅蓮の軌跡を描いて降り立つ！',
        ],
    ],
];
