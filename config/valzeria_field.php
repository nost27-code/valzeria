<?php

/*
|--------------------------------------------------------------------------
| ヴァルゼリア大陸フィールド（歩ける見下ろし型ワールドマップ）
|--------------------------------------------------------------------------
| 大陸は 37,500 x 50,000 マス。地形はマス単位で保存せず、次の2つから毎回同じ形に作る。
|   1. マクロ地図 public/images/field/valzeria-macro.png（地形帯・標高・海岸距離。scripts/field/build_valzeria_macro.py で生成）
|   2. このファイルの都市・街道・川・ダンジョン入口
| 本土の座標は「参照画像（scripts/field/valzeria_reference.webp を 600x800 にしたもの）の座標」で書く。
| 1 画像px = map_scale マス。浮遊島（セレスティア）は本土とは別の層で、座標は島の左上からのマス数で書く。
*/

return [
    'enabled' => filter_var(
        env('VALZERIA_FIELD_ENABLED', in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)),
        FILTER_VALIDATE_BOOL,
    ),

    'seed' => 20260928,
    'tile' => 32,              // 1マスの px
    'chunk_tiles' => 64,       // 地形を作る単位
    'world_tiles' => [37500, 50000],
    'map_scale' => 62.5,       // 参照画像 1px あたりのマス数（600x800 → 37,500x50,000）
    'macro' => [
        'path' => 'images/field/valzeria-macro.png',
        'size' => [600, 800],
    ],

    // 浮遊大陸（セレスティア）の層。本土の東（x = origin_x マス以降）に置き、転移陣でだけ行き来する
    'sky' => [
        'origin' => [40000, 0],
        'size' => [3000, 2400],
        'center' => [1500, 1150],
        'radius' => [1320, 980],
    ],

    'movement' => [
        'walk_tiles_per_second' => 9,   // 旧・走る速さ（5 x 1.8）を歩きにした
        'dash_multiplier' => 1.8,
    ],

    // ---- 見える魔物（シンボルエンカウント） ------------------------------------------------
    // 各ダンジョン入口のまわりは、そのエリアの魔物の縄張り。触れると既存の探索と同じ1戦（ExplorationService::explore）になる。
    // 探索力・クールタイム・報酬・負けた時の扱いはすべて既存の探索と同じ。
    'encounters' => [
        'territory_tiles' => 6000,        // 入口からこの距離までが縄張り（いちばん近い入口のエリア）。
        // まだ解放していないエリアの縄張りにも、その土地の魔物が出て戦える（2026-09-28 裁定）
        'safe_margin_tiles' => 60,        // 都市の城壁からこの距離までは魔物が出ない
        'entrance_safe_tiles' => 8,       // 入口の前も出ない
        'cell_tiles' => 20,               // 魔物を置く区画（画面1枚にだいたい2〜3体）
        'spawn_chance' => 0.75,           // 区画に1体目がいる確率
        'second_chance' => 0.4,           // 2体目
        'respawn_minutes' => 3,           // 倒した魔物が戻ってくるまで
        'sight_tiles' => 9,               // この距離に入ると追ってくる
        'wander_tiles_per_second' => 2,
        'chase_tiles_per_second' => 5,    // 歩き（9マス/秒）より遅い：逃げ切れる
        'graze_seconds' => 2.5,           // 戦闘の後、魔物に触れても戦わない時間
    ],

    // ---- 宝箱・採取ポイント（日替わりで復活） ---------------------------------------------------
    // ※ 数値は暫定。素材はそのエリアの通常の素材ドロップ表から1個。
    'gathering' => [
        'cell_tiles' => 120,
        'gather_chance' => 0.5,
        'chest_chance' => 0.1,
        'daily_gather_limit' => 30,
        'daily_chest_limit' => 10,
        'chest_gold_per_level' => 5,      // 宝箱の Gold = エリアの推奨Lv下限 x これ
        'reach_tiles' => 12,              // 最後に保存した位置からの距離
    ],

    // ---- 近くの人とのチャット ------------------------------------------------------------------
    'chat' => [
        'hearing_tiles' => 40,       // 話した場所からこの距離にいる人に届く
        'max_length' => 100,         // 全体チャットと同じ
        'cooldown_seconds' => 2,     // 連投の間隔
        'history_seconds' => 120,    // 近くに来た時に見える、少し前までの発言
        'keep_days' => 3,            // これより古い発言は消す
    ],

    'sync' => [
        'interval_seconds' => 1,
        'presence_seconds' => 10,    // 切断した冒険者を長く残さない
        'stationary_heartbeat_seconds' => 4, // 静止中は取得を続け、同じ位置のDB更新だけ間引く
        'presence_radius_tiles' => 48,
        'presence_limit' => 40,
        'action_reach_tiles' => 12,  // 施設・入口を使える、最後に保存した位置からの距離
        'city_reach_margin_tiles' => 8,
    ],

    // ---- 施設（都市の建物）。route は既存画面。inn は街画面（宿屋は街画面から使う） ----
    'facilities' => [
        'inn' => ['label' => '宿屋', 'route' => 'home', 'size' => [14, 10]],
        'supply' => ['label' => '補給所', 'route' => 'shop.items', 'size' => [10, 8]],
        'equipment_shop' => ['label' => '装備屋', 'route' => 'shop.equipment', 'size' => [12, 9]],
        'blacksmith' => ['label' => '鍛冶屋', 'route' => 'blacksmith.index', 'size' => [12, 9]],
        'synthesis' => ['label' => '合成屋', 'route' => 'smith.index', 'size' => [11, 9]],
        'material_exchange' => ['label' => '素材交換所', 'route' => 'material-exchange.index', 'size' => [11, 8]],
        'apothecary' => ['label' => '薬屋', 'route' => 'apothecary.index', 'size' => [10, 8]],
        'temple' => ['label' => '神殿', 'route' => 'jobs.index', 'size' => [16, 12]],
        'bank' => ['label' => '銀行', 'route' => 'bank.index', 'size' => [12, 9]],
        'tavern' => ['label' => '酒場', 'route' => 'tavern.index', 'size' => [13, 9]],
        'guide' => ['label' => '案内所', 'route' => 'town.guide', 'size' => [10, 8]],
        'ranking_board' => ['label' => '番付掲示板', 'route' => 'ranking.index', 'size' => [6, 3]],
        'map_house' => ['label' => '地図院', 'route' => 'exploration-maps.index', 'size' => [11, 9]],
        'valmon_farm' => ['label' => 'ヴァルモン牧場', 'route' => 'valmons.index', 'size' => [16, 11]],
        'training_ground' => ['label' => '冒険者訓練所', 'route' => 'training-ground.index', 'size' => [16, 12]],
    ],

    // どの都市にもある施設（都市ごとの 'facilities' で追加・除外できる）
    'standard_facilities' => [
        'inn', 'supply', 'equipment_shop', 'blacksmith', 'synthesis', 'material_exchange', 'apothecary',
        'temple', 'bank', 'tavern', 'guide', 'ranking_board', 'map_house', 'valmon_farm', 'training_ground',
    ],

    // ---- 都市 ---------------------------------------------------------------------------------
    // at: 中心（参照画像の座標。浮遊島は島の左上からのマス）、size: 街の外周の寸法（マス）
    // style は public/js/field/towns.js の街並みの種類
    'cities' => [
        1 => ['key' => 'arcrea', 'name' => '王都アークレア', 'style' => 'royal_capital', 'at' => [160, 578], 'size' => [240, 200], 'shape' => 'round',
            'landmark' => 'アークレア城', 'gates' => ['s', 'e', 'w']],
        2 => ['key' => 'marines', 'name' => '港町マリネス', 'style' => 'port', 'at' => [40, 420], 'size' => [170, 130], 'shape' => 'rect', 'harbor' => 'west',
            'landmark' => 'マリネス灯台'],
        3 => ['key' => 'elfia', 'name' => '精霊の森エルフィア', 'style' => 'tree_city', 'at' => [286, 372], 'size' => [190, 190], 'shape' => 'round',
            'landmark' => '世界樹'],
        4 => ['key' => 'granberg', 'name' => '鍛冶街グランベルグ', 'style' => 'forge', 'at' => [165, 250], 'size' => [160, 120], 'shape' => 'rect',
            'landmark' => '大溶鉱炉'],
        5 => ['key' => 'frostria', 'name' => '雪原の町フロストリア', 'style' => 'snow', 'at' => [432, 168], 'size' => [150, 115], 'shape' => 'rect',
            'landmark' => '氷晶の大聖堂'],
        6 => ['key' => 'sandra', 'name' => '砂漠の宿場サンドラ', 'style' => 'oasis', 'at' => [355, 695], 'size' => [170, 135], 'shape' => 'round',
            'landmark' => '命のオアシス'],
        7 => ['key' => 'luminas', 'name' => '魔導学院ルミナス', 'style' => 'academy', 'at' => [448, 420], 'size' => [180, 150], 'shape' => 'rect',
            'landmark' => '大魔導塔'],
        8 => ['key' => 'necrom', 'name' => '死霊街ネクロム', 'style' => 'necro', 'at' => [364, 556], 'size' => [165, 130], 'shape' => 'rect',
            'landmark' => '黒曜の大聖堂'],
        9 => ['key' => 'celestia', 'name' => '天空神殿セレスティア', 'style' => 'sky_temple', 'plane' => 'sky', 'at' => [1500, 1080], 'size' => [190, 150], 'shape' => 'round',
            'landmark' => '天空大神殿'],
        10 => ['key' => 'valzeria', 'name' => '魔王城ヴァルゼリア', 'style' => 'demon_castle', 'at' => [322, 38], 'size' => [210, 160], 'shape' => 'rect',
            'landmark' => '魔王城', 'gates' => ['s']],
    ],

    // ---- 街道（from / to は都市キーか waypoints のキー。via は途中の点） ----------------------------
    'waypoints' => [
        'volcano_hub' => [322, 214],   // 魔王の火山の麓。ここから登山道が始まる
        'sky_tower' => [442, 304],     // 天空の転移塔（岬の上）
        'sky_arrival' => ['plane' => 'sky', 'at' => [1500, 2020]],
    ],

    'roads' => [
        ['key' => 'arcrea_marines', 'from' => 'arcrea', 'to' => 'marines', 'via' => [[128, 530], [104, 480], [80, 446]]],
        ['key' => 'arcrea_elfia', 'from' => 'arcrea', 'to' => 'elfia', 'via' => [[196, 520], [230, 470], [262, 432]]],
        ['key' => 'arcrea_necrom', 'from' => 'arcrea', 'to' => 'necrom', 'via' => [[222, 592], [280, 584], [322, 566]]],
        ['key' => 'necrom_sandra', 'from' => 'necrom', 'to' => 'sandra', 'via' => [[352, 612], [344, 652]]],
        ['key' => 'necrom_luminas', 'from' => 'necrom', 'to' => 'luminas', 'via' => [[402, 520], [430, 472]]],
        ['key' => 'elfia_luminas', 'from' => 'elfia', 'to' => 'luminas', 'via' => [[344, 396], [398, 412]]],
        ['key' => 'marines_granberg', 'from' => 'marines', 'to' => 'granberg', 'via' => [[70, 372], [100, 322], [138, 286]]],
        ['key' => 'elfia_granberg', 'from' => 'elfia', 'to' => 'granberg', 'via' => [[250, 318], [210, 290]]],
        ['key' => 'elfia_frostria', 'from' => 'elfia', 'to' => 'frostria', 'via' => [[322, 298], [366, 258], [402, 214]]],
        ['key' => 'elfia_volcano', 'from' => 'elfia', 'to' => 'volcano_hub', 'via' => [[300, 300], [316, 254]]],
        ['key' => 'granberg_volcano', 'from' => 'granberg', 'to' => 'volcano_hub', 'via' => [[216, 236], [272, 224]]],
        ['key' => 'frostria_volcano', 'from' => 'frostria', 'to' => 'volcano_hub', 'via' => [[398, 214], [360, 218]]],
        ['key' => 'luminas_sky_tower', 'from' => 'luminas', 'to' => 'sky_tower', 'via' => [[452, 372], [446, 336]]],
        // 魔王の火山の登山道：麓の溶岩の堀を渡り、段々をつづら折りに登って山頂の魔王城へ
        ['key' => 'valzeria_ascent', 'from' => 'volcano_hub', 'to' => 'valzeria', 'restricted' => true, 'paved' => true,
            'via' => [[322, 190], [272, 170], [372, 150], [270, 128], [366, 108], [292, 88], [350, 68], [322, 60]]],
        // 浮遊島：転移陣から大神殿へ
        ['key' => 'sky_main', 'plane' => 'sky', 'from' => 'sky_arrival', 'to' => 'celestia', 'paved' => true, 'via' => [[1500, 1700]]],
    ],

    // 魔王の火山の麓の結界。都市10が解放されるまで登山道を塞ぐ
    'barriers' => [
        ['key' => 'valzeria_seal', 'name' => '魔王の結界', 'road' => 'valzeria_ascent', 'at' => [322, 190], 'unlock_city_id' => 10],
    ],

    // 天空の転移陣。unlock_city_id の都市が解放されていれば使える
    'teleporters' => [
        ['key' => 'sky_gate_land', 'name' => '天空の転移塔', 'waypoint' => 'sky_tower', 'to' => 'sky_gate_sky', 'unlock_city_id' => 9],
        ['key' => 'sky_gate_sky', 'name' => '天空の転移陣', 'waypoint' => 'sky_arrival', 'to' => 'sky_gate_land', 'unlock_city_id' => 9],
    ],

    // ---- 川（width はマス） ------------------------------------------------------------------------
    // 幅（マス）は参照画像の川を実測して決めた（1px = 62.5マス）。
    //   北〜中央の川：絵で 2〜3.5px → 約130〜220マス / 王都東の入り江から南の下流：4〜8.5px → 約250〜530マス
    'rivers' => [
        ['key' => 'lumina_river', 'name' => 'ルミナ川', 'width' => [130, 300],
            'points' => [[332, 226], [362, 258], [386, 286], [410, 314], [422, 350], [428, 382], [420, 416], [394, 440], [360, 456], [330, 472], [296, 494], [266, 500], [238, 512]]],
        // ルミナ川は王都の東で南の大河に合流し、南西の海へ注ぐ
        ['key' => 'south_river', 'name' => '南の大河', 'width' => [300, 530],
            'points' => [[236, 512], [232, 516], [244, 540], [262, 566], [282, 600], [290, 630], [272, 660], [246, 684], [218, 704], [204, 716]]],
        // 絵には無いオリジナルの沢（絵の世界観に沿ったオリジナル要素は少し加えてよい方針。幅は控えめ）
        ['key' => 'frost_stream', 'name' => '雪解けの沢', 'width' => [40, 70],
            'points' => [[470, 206], [452, 236], [420, 244], [396, 244]]],
        ['key' => 'granberg_stream', 'name' => '鉱山の沢', 'width' => [40, 80],
            'points' => [[196, 214], [214, 262], [226, 300], [220, 340], [196, 380], [150, 400], [110, 400], [80, 396]]],
    ],

    // ---- ダンジョン入口（area_id は areas.id） -----------------------------------------------------
    // kind は入口の見た目（public/js/field/entrances.js）。road を書くとその街道へ小道をつなぐ（既定は近い街道）
    'entrances' => [
        // 王都アークレア
        ['area_id' => 1, 'kind' => 'meadow', 'at' => [188, 622]],
        ['area_id' => 2, 'kind' => 'forest', 'at' => [216, 546]],
        ['area_id' => 3, 'kind' => 'cave', 'at' => [96, 560]],
        ['area_id' => 4, 'kind' => 'hill', 'at' => [118, 642]],
        ['area_id' => 5, 'kind' => 'graveyard', 'at' => [232, 652]],
        ['area_id' => 6, 'kind' => 'spring', 'at' => [112, 512]],
        ['area_id' => 7, 'kind' => 'training', 'at' => [160, 524]],
        // 港町マリネス
        ['area_id' => 8, 'kind' => 'beach', 'at' => [76, 470]],
        ['area_id' => 9, 'kind' => 'sea_cave', 'at' => [62, 360]],
        ['area_id' => 10, 'kind' => 'shipwreck', 'at' => [52, 448]],
        ['area_id' => 11, 'kind' => 'cove', 'at' => [72, 332]],
        ['area_id' => 12, 'kind' => 'hideout', 'at' => [64, 292]],
        ['area_id' => 13, 'kind' => 'coral', 'at' => [56, 396]],
        ['area_id' => 14, 'kind' => 'sea_temple', 'at' => [90, 500]],
        // 精霊の森エルフィア
        ['area_id' => 15, 'kind' => 'forest', 'at' => [232, 334]],
        ['area_id' => 16, 'kind' => 'fairy_forest', 'at' => [338, 322]],
        ['area_id' => 17, 'kind' => 'roots', 'at' => [290, 412]],
        ['area_id' => 18, 'kind' => 'tree_door', 'at' => [270, 350]],
        ['area_id' => 19, 'kind' => 'tree_door', 'at' => [304, 350]],
        ['area_id' => 20, 'kind' => 'temple', 'at' => [232, 402]],
        ['area_id' => 21, 'kind' => 'garden', 'at' => [344, 394]],
        // 鍛冶街グランベルグ
        ['area_id' => 22, 'kind' => 'mine', 'at' => [130, 212]],
        ['area_id' => 23, 'kind' => 'mine', 'at' => [198, 214]],
        ['area_id' => 24, 'kind' => 'furnace', 'at' => [120, 268]],
        ['area_id' => 25, 'kind' => 'factory', 'at' => [206, 266]],
        ['area_id' => 26, 'kind' => 'factory', 'at' => [232, 246]],
        ['area_id' => 27, 'kind' => 'cave', 'at' => [96, 246]],
        ['area_id' => 28, 'kind' => 'ruins', 'at' => [152, 178]],
        ['area_id' => 72, 'kind' => 'ancient_forge', 'at' => [182, 152]],
        // 雪原の町フロストリア
        ['area_id' => 29, 'kind' => 'snowfield', 'at' => [402, 202]],
        ['area_id' => 30, 'kind' => 'canyon', 'at' => [472, 196]],
        ['area_id' => 31, 'kind' => 'ice_cave', 'at' => [458, 122]],
        ['area_id' => 32, 'kind' => 'snow_forest', 'at' => [446, 222]],
        ['area_id' => 33, 'kind' => 'temple', 'at' => [502, 166]],
        ['area_id' => 34, 'kind' => 'dragon_lair', 'at' => [508, 112]],
        ['area_id' => 35, 'kind' => 'mountain', 'at' => [470, 86]],
        // 砂漠の宿場サンドラ
        ['area_id' => 36, 'kind' => 'dunes', 'at' => [300, 702]],
        ['area_id' => 37, 'kind' => 'quicksand', 'at' => [412, 702]],
        ['area_id' => 38, 'kind' => 'ruins', 'at' => [286, 652]],
        ['area_id' => 39, 'kind' => 'pyramid', 'at' => [422, 656]],
        ['area_id' => 40, 'kind' => 'temple', 'at' => [330, 746]],
        ['area_id' => 41, 'kind' => 'cave', 'at' => [372, 732]],
        ['area_id' => 42, 'kind' => 'sun_temple', 'at' => [404, 748]],
        // 魔導学院ルミナス
        ['area_id' => 43, 'kind' => 'library', 'at' => [484, 432]],
        ['area_id' => 44, 'kind' => 'library', 'at' => [410, 442]],
        ['area_id' => 45, 'kind' => 'lab', 'at' => [470, 458]],
        ['area_id' => 46, 'kind' => 'garden', 'at' => [414, 386]],
        ['area_id' => 47, 'kind' => 'tower', 'at' => [492, 396]],
        ['area_id' => 48, 'kind' => 'observatory', 'at' => [508, 448]],
        ['area_id' => 49, 'kind' => 'portal', 'at' => [452, 478]],
        ['area_id' => 74, 'kind' => 'portal', 'at' => [478, 492]],
        // 死霊街ネクロム
        ['area_id' => 50, 'kind' => 'wasteland', 'at' => [322, 560]],
        ['area_id' => 51, 'kind' => 'cursed_castle', 'at' => [402, 530]],
        ['area_id' => 52, 'kind' => 'underworld_gate', 'at' => [346, 524]],
        ['area_id' => 53, 'kind' => 'temple', 'at' => [412, 582]],
        ['area_id' => 54, 'kind' => 'fortress', 'at' => [330, 596]],
        ['area_id' => 55, 'kind' => 'canyon', 'at' => [396, 606]],
        ['area_id' => 56, 'kind' => 'abyss_stairs', 'at' => [366, 600]],
        ['area_id' => 71, 'kind' => 'rift', 'at' => [442, 562]],
        // 天空神殿セレスティア（浮遊島のマス座標）
        ['area_id' => 57, 'plane' => 'sky', 'kind' => 'cloud_field', 'at' => [760, 1420]],
        ['area_id' => 58, 'plane' => 'sky', 'kind' => 'sky_corridor', 'at' => [2240, 760]],
        ['area_id' => 59, 'plane' => 'sky', 'kind' => 'thunder_temple', 'at' => [2330, 1360]],
        ['area_id' => 60, 'plane' => 'sky', 'kind' => 'ruins', 'at' => [700, 660]],
        ['area_id' => 61, 'plane' => 'sky', 'kind' => 'garden', 'at' => [1880, 1720]],
        ['area_id' => 62, 'plane' => 'sky', 'kind' => 'tower', 'at' => [1500, 380]],
        ['area_id' => 63, 'plane' => 'sky', 'kind' => 'altar', 'at' => [2520, 1060]],
        ['area_id' => 73, 'plane' => 'sky', 'kind' => 'dragon_sanctuary', 'at' => [420, 1060]],
        // 魔王城ヴァルゼリア（火山の段々に沿って。登山道にだけつなぐ）
        ['area_id' => 64, 'kind' => 'demon_gate', 'at' => [304, 178], 'road' => 'valzeria_ascent'],
        ['area_id' => 65, 'kind' => 'dark_corridor', 'at' => [262, 158], 'road' => 'valzeria_ascent'],
        ['area_id' => 66, 'kind' => 'demon_hall', 'at' => [380, 140], 'road' => 'valzeria_ascent'],
        ['area_id' => 67, 'kind' => 'prison', 'at' => [262, 120], 'road' => 'valzeria_ascent'],
        ['area_id' => 68, 'kind' => 'throne', 'at' => [374, 100], 'road' => 'valzeria_ascent'],
        ['area_id' => 69, 'kind' => 'castle_core', 'at' => [286, 78], 'road' => 'valzeria_ascent'],
        ['area_id' => 70, 'kind' => 'final_altar', 'at' => [356, 60], 'road' => 'valzeria_ascent'],
    ],

    // ---- 地名（いちばん近い地名を画面に出す） ---------------------------------------------------------
    'regions' => [
        ['name' => 'アークレア王領', 'at' => [168, 592]],
        ['name' => '西の海岸', 'at' => [84, 480]],
        ['name' => 'マリネス湾', 'at' => [52, 408]],
        ['name' => '北西の岩山', 'at' => [160, 190]],
        ['name' => 'グランベルグ高原', 'at' => [150, 260]],
        ['name' => '北西の丘陵', 'at' => [96, 300]],
        ['name' => '世界樹の大森林', 'at' => [286, 362]],
        ['name' => '中央平原', 'at' => [232, 470]],
        ['name' => 'ルミナスの丘', 'at' => [452, 420]],
        ['name' => '東の岬', 'at' => [446, 300]],
        ['name' => 'フロストリア雪原', 'at' => [452, 170]],
        ['name' => '北の雪嶺', 'at' => [490, 90]],
        ['name' => 'ヴァルゼリア火山', 'at' => [322, 110]],
        ['name' => '火山の麓', 'at' => [322, 214]],
        ['name' => 'ネクロムの瘴気地', 'at' => [364, 556]],
        ['name' => '南の平野', 'at' => [270, 616]],
        ['name' => 'サンドラ砂漠', 'at' => [360, 704]],
        ['name' => '東の海岸', 'at' => [512, 520]],
        ['name' => '南西の入り江', 'at' => [220, 700]],
        ['name' => '雲上の浮遊大陸', 'plane' => 'sky', 'at' => [1500, 1150]],
    ],
];
