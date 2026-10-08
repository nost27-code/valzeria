<?php

// 100万分の固定抽選枠。既知/保管済みの枠はハズレにし、他種へ再配分しない。
// 管理設定倍率・日次上限・育成能力は共通ValmonServiceを使用する。
return [
    'ticket_units' => ['normal' => 200, 'rare' => 20],
    'masters' => [
        'ruin_sunakor' => ['no' => 22, 'zone' => 'sand', 'name' => 'スナコル', 'rarity' => 'normal', 'type' => '砂耳フェネック型', 'description' => '砂色の毛と大きな耳を持つ小さな獣。砂埋の参道で、古い首飾りを身につけて暮らす。'],
        'ruin_kohakuwa' => ['no' => 23, 'zone' => 'sand', 'name' => 'コハクワ', 'rarity' => 'normal', 'type' => '琥珀スカラベ型', 'description' => '琥珀色の体と青銅の甲殻を持つ甲虫。砂埋の参道の石碑の陰に潜む。'],
        'ruin_mizuroru' => ['no' => 24, 'zone' => 'water', 'name' => 'ミズロル', 'rarity' => 'normal', 'type' => '沈鐘ウーパールーパー型', 'description' => '水色の体と淡い珊瑚色の鰓を持つ小さな水棲獣。沈水の水路で古い鐘を首に下げる。'],
        'ruin_tsubokari' => ['no' => 25, 'zone' => 'water', 'name' => 'ツボカリ', 'rarity' => 'normal', 'type' => '祭器ヤドカリ型', 'description' => '青い祭器の壺を背負う小さなヤドカリ。沈水の水路の苔むした石段を歩く。'],
        'ruin_hinotoru' => ['no' => 26, 'zone' => 'forge', 'name' => 'ヒノトル', 'rarity' => 'normal', 'type' => '炉火サンショウウオ型', 'description' => '炭色の体と小さな炎の尾を持つ獣。褪火の鋳造区の残り火に寄り添う。'],
        'ruin_kanamaru' => ['no' => 27, 'zone' => 'forge', 'name' => 'カナマル', 'rarity' => 'normal', 'type' => '金属鱗センザンコウ型', 'description' => '金属の鱗で身を守る小さな獣。褪火の鋳造区で、金床のような額の鱗を見せる。'],
        'ruin_fumifuku' => ['no' => 28, 'zone' => 'library', 'name' => 'フミフク', 'rarity' => 'normal', 'type' => '古書フクロウ型', 'description' => '丸い体に古書を背負うフクロウ。禁書の回廊で、訪れる者を静かに見つめる。'],
        'ruin_shiorina' => ['no' => 29, 'zone' => 'library', 'name' => 'シオリナ', 'rarity' => 'normal', 'type' => '羊皮紙羽モス型', 'description' => '羊皮紙のような羽と封蝋の飾りを持つ蛾。禁書の回廊の書架の間を舞う。'],
        'ruin_hoshiriru' => ['no' => 30, 'zone' => 'observatory', 'name' => 'ホシリル', 'rarity' => 'normal', 'type' => '星紋ヤモリ型', 'description' => '群青の体に星模様を持つ小さなヤモリ。砕星の観測庭で、尾に古い観測環をつける。'],
        'ruin_suisemu' => ['no' => 31, 'zone' => 'observatory', 'name' => 'スイセム', 'rarity' => 'normal', 'type' => '彗星尾コウモリ型', 'description' => '星の点が散る翼と彗星のような尾を持つコウモリ。砕星の観測庭の夜を滑る。'],
        'ruin_makijaru' => ['no' => 32, 'zone' => 'tomb', 'name' => 'マキジャル', 'rarity' => 'normal', 'type' => '包帯ジャッカル型', 'description' => '古い首飾りと包帯をまとう小さなジャッカル。無名の王墓で静かに耳を澄ます。'],
        'ruin_kanmuryu' => ['no' => 33, 'zone' => 'tomb', 'name' => 'カンムリュ', 'rarity' => 'normal', 'type' => '葬冠猫精霊型', 'description' => '欠けた王冠をかぶる淡青の猫の精霊。無名の王墓で短い外套をなびかせる。'],
        'ruin_astrei' => ['no' => 34, 'zone' => 'observatory', 'name' => 'アストレイ', 'rarity' => 'rare', 'type' => '星鎧幼竜型', 'description' => '紺黒と金の星鎧をまとう希少な幼竜。砕星の観測庭で、胸の蒼い星核と三日月の翼を輝かせる。'],
        'ruin_luxion' => ['no' => 35, 'zone' => 'water', 'name' => 'ルクシオン', 'rarity' => 'rare', 'type' => '白晶麒麟型', 'description' => '白い毛と氷晶の角を持つ希少な麒麟。沈水の水路に姿を現し、古代金の首飾りをまとう。'],
        'ruin_noxia' => ['no' => 36, 'zone' => 'tomb', 'name' => 'ノクシア', 'rarity' => 'rare', 'type' => '冥冠鳳凰型', 'description' => '黒銀の羽と紫晶の冠羽を持つ希少な鳳凰。無名の王墓で三枚の王羽を静かに広げる。'],
    ],
];
