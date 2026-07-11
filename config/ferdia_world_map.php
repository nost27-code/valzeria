<?php

$node = static function (
    string $key,
    string $name,
    string $type,
    string $group,
    int $sequence,
    float $x,
    float $y,
    ?int $areaId,
    ?int $cityId,
    array $unlock,
    ?array $reveal = null,
    int $max = 100,
    array $events = [],
    array $storyRecord = []
): array {
    return [
        'key' => $key,
        'name' => $name,
        'node_type' => $type,
        'route_group' => $group,
        'sequence' => $sequence,
        'x_percent' => $x,
        'y_percent' => $y,
        'area_id' => $areaId,
        'city_id' => $cityId,
        'unlock' => $unlock,
        'reveal' => $reveal,
        'max_development_point' => $max,
        'events' => $events,
        'story_record' => $storyRecord,
    ];
};

$development = static fn (string $key, int $point): array => [
    'type' => 'node_development',
    'node_key' => $key,
    'required_point' => $point,
];

$boss = static fn (string $key): array => [
    'type' => 'node_boss_defeated',
    'node_key' => $key,
];

$city = static fn (string $key): array => [
    'type' => 'city_discovered',
    'node_key' => $key,
];

$allNodes = static fn (array $keys): array => [
    'type' => 'all_nodes_completed',
    'node_keys' => $keys,
];

return [
    'content_key' => 'ferdia_unlocked',
    'name' => 'フェルディア大陸',
    'subtitle' => '緑豊かなる連邦の大地',
    'map_image' => 'images/map/map02.webp',
    'placeholder_image' => 'images/map/unexplored_region10.webp',
    'development_gain' => [
        'base' => 1,
        'bonus' => 1,
        'bonus_chance_percent' => 40,
    ],
    'story_final_unlock' => [
        'final_node_key' => 'abyss_prelude',
        'required_node_keys' => ['stargazer_ruin', 'aquarius_shrine', 'ordo_columns', 'white_tide_lighthouse'],
    ],
    'cities' => [
        'luvan' => [
            'id' => 101,
            'name' => '辺境の町ルヴァン',
            'description' => 'フェルディア南西の街道に寄り添う小さな拠点。旅人と調査隊が行き交っている。',
            'recommended_level_min' => 142,
            'recommended_level_max' => 148,
            'sort_order' => 110,
        ],
        'granford' => [
            'id' => 102,
            'name' => '王都グランフォード',
            'description' => '青い尖塔と古王国の石畳が残る、フェルディア中央の大都市。',
            'recommended_level_min' => 154,
            'recommended_level_max' => 162,
            'sort_order' => 120,
        ],
        'arven' => [
            'id' => 103,
            'name' => '港町アーヴェン',
            'description' => '東の大港。交易船と古い航路の噂が集まる、フェルディアの海の玄関口。',
            'recommended_level_min' => 166,
            'recommended_level_max' => 174,
            'sort_order' => 130,
        ],
    ],
    'nodes' => [
        $node('ferdia_south_coast', 'フェルディア南岸', 'landing', 'main', 1, 52, 87, 1001, null, ['type' => 'region_unlocked'], null, 100, [
            30 => '砂に埋もれた古い標柱を見つけた。',
            60 => '内陸へ続く街道の跡が見える。',
            100 => '潮風の街道が解放される。',
        ]),
        $node('shiokaze_road', '潮風の街道', 'road', 'main', 2, 39, 77, 1002, null, $development('ferdia_south_coast', 100), $development('ferdia_south_coast', 60), 100, [
            30 => '旅人の古い野営跡を見つけた。',
            60 => '丘の上へ続く道が見える。',
            100 => '見晴らしの丘道が解放される。',
        ]),
        $node('miharashi_hill_road', '見晴らしの丘道', 'road', 'main', 3, 27, 63, 1003, null, $development('shiokaze_road', 100), $development('shiokaze_road', 60), 150, [
            30 => '石畳の残る古い道を見つけた。',
            60 => '遠くに小さな町の屋根が見える。',
            100 => '丘陵の守護獣が道を塞いだ。討伐すればルヴァンへ進める。',
        ]),
        $node('luvan', '辺境の町ルヴァン', 'city', 'main', 4, 17, 75, null, 101, $boss('miharashi_hill_road'), $development('miharashi_hill_road', 60)),
        $node('seiryu_limiere', '清流リミュエール', 'river', 'main', 5, 35, 59, 1004, null, $development('miharashi_hill_road', 100), $development('miharashi_hill_road', 60), 100, [
            30 => '川沿いに古い積み場跡を見つけた。',
            60 => '滝の向こうに崩れた石橋が見える。',
            100 => '古道の石橋跡が解放される。',
        ]),
        $node('old_stone_bridge', '古道の石橋跡', 'ruin', 'main', 6, 39, 50, 1005, null, $development('seiryu_limiere', 100), $development('seiryu_limiere', 60), 100, [
            30 => '橋脚に古王国の紋章を見つけた。',
            60 => '川向こうに白い柱の遺跡が見える。',
            100 => 'アーデル遺跡が解放される。',
        ]),
        $node('adel_ruins', 'アーデル遺跡', 'ruin', 'main', 7, 39, 36, 1006, null, $development('old_stone_bridge', 100), $development('old_stone_bridge', 60), 150, [
            30 => '石板に古い補給記録が刻まれている。',
            60 => '遺跡の高所から王都の尖塔が見える。',
            100 => '王都グランフォード外郭路が解放される。',
        ]),
        $node('granford_outer', '王都グランフォード外郭路', 'road', 'main', 8, 45, 46, 1007, null, $development('adel_ruins', 100), $development('adel_ruins', 60), 150, [
            30 => '城壁へ続く石畳を見つけた。',
            60 => '大きな城門と青い尖塔が見える。',
            100 => '外郭の古王近衛が立ちはだかる。討伐すればグランフォードへ進める。',
        ]),
        $node('granford', '王都グランフォード', 'city', 'main', 9, 52, 49, null, 102, $boss('granford_outer'), $development('granford_outer', 60)),
        $node('meia_plain', 'メイア河畔道', 'road', 'main', 10, 73, 55, 1008, null, $city('granford'), null, 100, [
            30 => '川沿いに古い道標が残っている。',
            60 => '東の丘陵に水門と運河が見える。',
            100 => '水門街道が解放される。',
        ]),
        $node('suimon_road', '水門街道', 'road', 'main', 11, 73, 63, 1009, null, $development('meia_plain', 100), $development('meia_plain', 60), 150, [
            30 => '運河沿いに荷運び路が続いている。',
            60 => '遠くに帆柱と港の灯が見える。',
            100 => '水門の守護巨獣が姿を現した。討伐すればアーヴェンへ進める。',
        ]),
        $node('arven', '港町アーヴェン', 'port', 'main', 12, 80, 76, null, 103, $boss('suimon_road'), $development('suimon_road', 60)),
        $node('shizumori', '静深き北森', 'forest', 'main', 13, 75, 40, 1010, null, $city('arven'), null, 100, [
            30 => '森の奥に巡礼路の石碑を見つけた。',
            60 => '木々の向こうに大樹の城影が見える。',
            100 => '大樹の聖城外縁が解放される。',
        ]),
        $node('seijo_outer', '大樹の聖城外縁', 'road', 'main', 14, 64, 34, 1011, null, $development('shizumori', 100), $development('shizumori', 60), 100, [
            30 => '巡礼者の古い碑文を見つけた。',
            60 => '聖城の全景がはっきりと見える。',
            100 => '大樹の聖城が解放される。',
        ]),
        $node('daiju_seijo', '大樹の聖城', 'castle', 'main', 15, 62, 29, 1012, null, $development('seijo_outer', 100), $development('seijo_outer', 60), 100, [
            30 => '根の奥に北へ続く古い巡礼印を見つけた。',
            60 => '遥か北に白い霊峰が見える。',
            100 => '北境の霊峰エルヴァンが解放される。',
        ]),
        $node('elvan_peak', '北境の霊峰エルヴァン', 'mountain', 'main', 16, 50, 18, 1013, null, $development('daiju_seijo', 100), $development('daiju_seijo', 60), 100, [
            100 => '霊峰の頂で氷冠竜が目を覚ました。フェルディアの踏破を証明しよう。',
        ]),
        $node('stargazer_ruin', '星詠みの廃塔', 'ruin', 'story', 17, 18, 37, 1025, null, $development('adel_ruins', 100), $development('adel_ruins', 60), 100, [
            100 => '欠けた星図石板を読み解いた。読める箇所を手帳に写しておこう。',
        ], [
            'text' => '欠けた星図石板には、北の雪山の山腹を指す四つの星が刻まれていた。星の下には「凍てた峰の奥、青白い光を追え」とある。',
        ]),
        $node('aquarius_shrine', '瀑布神殿アクエリス', 'river', 'story', 18, 88, 35, 1026, null, $development('shizumori', 100), $development('shizumori', 60), 100, [
            100 => '神殿の水路脇で、水脈碑を見つけた。読める箇所を手帳に写しておこう。',
        ], [
            'text' => '水脈碑には「潮が大きく引く夜、滝つぼの底に石の扉が現れる」と刻まれている。',
        ]),
        $node('ordo_columns', '風化列柱都市オルド', 'ruin', 'story', 19, 86, 52, 1027, null, $boss('suimon_road'), $development('suimon_road', 100), 100, [
            100 => '列柱の足元で、古い水路碑文を見つけた。読める箇所を手帳に写しておこう。',
        ], [
            'text' => '水路碑文には「王の水は地の底を巡り、王印の下で再び地上へ戻る」と刻まれている。',
        ]),
        $node('white_tide_lighthouse', '白潮灯台', 'port', 'story', 20, 90, 85, 1028, null, $city('arven'), null, 100, [
            100 => '灯台の書庫で、古い航海日誌を見つけた。読める箇所を手帳に写しておこう。',
        ], [
            'text' => '航海日誌には「濃い霧の沖で鐘の音を追うな。黒い船影は帰る海を忘れさせる」と記されている。',
        ]),
        $node('abyss_prelude', '地下の謎の穴', 'ruin', 'story', 21, 67, 79, 1029, null, $allNodes(['stargazer_ruin', 'aquarius_shrine', 'ordo_columns', 'white_tide_lighthouse']), null, 100, [
            100 => '大陸横断の手帳が示す、アビスへ続く深い穴を調べ尽くした。',
        ]),
    ],
    'bosses' => [
        1003 => ['name' => '丘陵の守護獣グリムウルフ', 'family_key' => 'beast', 'variant_key' => 'forest', 'type_name' => '獣', 'element' => '風'],
        1007 => ['name' => '外郭の古王近衛アストレア', 'family_key' => 'soldier', 'variant_key' => 'ancient', 'type_name' => '人型', 'element' => '古代'],
        1009 => ['name' => '水門の守護巨獣リヴァイア', 'family_key' => 'aquatic', 'variant_key' => 'forest', 'type_name' => '水棲', 'element' => '水'],
        1013 => ['name' => '霊峰の氷冠竜エルヴァン', 'family_key' => 'dragon', 'variant_key' => 'ancient', 'type_name' => '竜', 'element' => '氷'],
    ],
    'routes' => [
        ['from' => 'ferdia_south_coast', 'to' => 'shiokaze_road', 'group' => 'main'],
        ['from' => 'shiokaze_road', 'to' => 'miharashi_hill_road', 'group' => 'main'],
        ['from' => 'miharashi_hill_road', 'to' => 'luvan', 'group' => 'branch'],
        ['from' => 'miharashi_hill_road', 'to' => 'seiryu_limiere', 'group' => 'main'],
        ['from' => 'seiryu_limiere', 'to' => 'old_stone_bridge', 'group' => 'main'],
        ['from' => 'old_stone_bridge', 'to' => 'adel_ruins', 'group' => 'main'],
        ['from' => 'adel_ruins', 'to' => 'granford_outer', 'group' => 'main'],
        ['from' => 'granford_outer', 'to' => 'granford', 'group' => 'main'],
        ['from' => 'granford', 'to' => 'meia_plain', 'group' => 'main'],
        ['from' => 'meia_plain', 'to' => 'suimon_road', 'group' => 'main'],
        ['from' => 'suimon_road', 'to' => 'arven', 'group' => 'main'],
        ['from' => 'arven', 'to' => 'shizumori', 'group' => 'main'],
        ['from' => 'shizumori', 'to' => 'seijo_outer', 'group' => 'main'],
        ['from' => 'seijo_outer', 'to' => 'daiju_seijo', 'group' => 'main'],
        ['from' => 'daiju_seijo', 'to' => 'elvan_peak', 'group' => 'main'],
        ['from' => 'adel_ruins', 'to' => 'stargazer_ruin', 'group' => 'story'],
        ['from' => 'shizumori', 'to' => 'aquarius_shrine', 'group' => 'story'],
        ['from' => 'suimon_road', 'to' => 'ordo_columns', 'group' => 'story'],
        ['from' => 'arven', 'to' => 'white_tide_lighthouse', 'group' => 'story'],
        ['from' => 'ordo_columns', 'to' => 'abyss_prelude', 'group' => 'story_final'],
    ],
];
