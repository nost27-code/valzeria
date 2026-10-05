<?php

// ローカル試作専用。既存の敵・エリアIDを消費しない仮想マスタ。
$zones = [
    'sand' => [
        'name' => '砂埋の参道', 'description' => '砂に埋もれた門と、巡礼者の石碑が連なる入口。',
        'effects' => ['stat_hp', 'stat_def', 'killer_beast', 'killer_insect', 'special_first_guard'],
        'enemies' => [
            ['name' => '石屑スライム', 'species' => 'slime', 'profile' => 'guard', 'art' => '琥珀色の半透明の体に欠けた石碑を抱えた粘体。砂が裾を流れ、内部の古文字だけが淡く光る。'],
            ['name' => '青銅牙の遺跡鼠', 'species' => 'beast', 'profile' => 'swift', 'art' => '太い前脚を持つ大鼠。前歯は緑青の浮いた青銅、背中には小さな盗掘道具と石粉。'],
            ['name' => '封泥スカラベ', 'species' => 'insect', 'profile' => 'guard', 'art' => '封印の印影が残る丸い泥玉を押す甲虫。青銅の甲殻と欠けた朱色の封泥を対比させる。'],
            ['name' => '碑文の番兵', 'species' => 'machine', 'profile' => 'balanced', 'art' => '石碑が胴体になった低重心の石人形。文字の目、楔形の槍、片脚に積もった砂。'],
            ['name' => '門喰い獣バルガド', 'species' => 'beast', 'profile' => 'brute', 'art' => '門扉を噛み砕く巨大な四足獣。背に折れた門柱、石歯の大顎、砂色の分厚い皮。'],
        ],
    ],
    'water' => [
        'name' => '沈水の水路', 'description' => '止まった水車と、鳴るはずのない鐘が沈む水路。',
        'effects' => ['stat_mp', 'stat_spr', 'killer_aquatic', 'killer_slime', 'special_victory_hp', 'special_victory_sp'],
        'enemies' => [
            ['name' => '陶殻ヤドカリ', 'species' => 'aquatic', 'profile' => 'guard', 'art' => '青い祭器の壺を殻にしたヤドカリ。釉薬の亀裂、壺の口から大きな赤い鋏。'],
            ['name' => '鎖藻の水蛇', 'species' => 'aquatic', 'profile' => 'swift', 'art' => '藻と鎖が絡む太い水蛇。鎖の環を背びれのように立て、濡れた黒緑の鱗を簡潔に描く。'],
            ['name' => '水鏡クラゲ', 'species' => 'aquatic', 'profile' => 'mage', 'art' => '平たい鏡面の傘に古い天井が映るクラゲ。水色の縁光と、雫が連なる短い触手。'],
            ['name' => '沈鐘の亡霊', 'species' => 'undead', 'profile' => 'mage', 'art' => '割れた釣鐘を胸に抱く水死の霊。裾は水煙、顔は鐘の穴の暗がりから覗く。'],
            ['name' => '貯水殿主ネレガル', 'species' => 'aquatic', 'profile' => 'guard', 'art' => '貯水槽の石蓋を背負う巨大な両生獣。苔むす王冠状の角と、喉袋の青い灯り。'],
        ],
    ],
    'forge' => [
        'name' => '褪火の鋳造区', 'description' => '火を失ってなお、槌の音だけが響く工廠。',
        'effects' => ['stat_str', 'killer_machine', 'killer_soldier', 'special_finisher'],
        'enemies' => [
            ['name' => '炉灰コボルト', 'species' => 'soldier', 'profile' => 'brute', 'art' => '煤だらけの小柄な犬頭の鍛冶工。大きな耐熱手袋と短い槌、腰に空の火種瓶。'],
            ['name' => '鉄滓ムカデ', 'species' => 'insect', 'profile' => 'swift', 'art' => '鉄滓の節を連ねた短く太いムカデ。腹の継ぎ目だけが赤熱し、脚は釘状。'],
            ['name' => '廃炉の鍛冶偶', 'species' => 'machine', 'profile' => 'guard', 'art' => '冷えた小炉を腹に持つ人形。片腕が鉗子、片腕が槌。欠けた煉瓦と消えかけの火。'],
            ['name' => '錆喰いの鎧獣', 'species' => 'beast', 'profile' => 'brute', 'art' => '捨てられた胸甲をまとう猪型の獣。鼻先に鉄粉、錆色の剛毛と厚い脚。'],
            ['name' => '炉心巨兵ドゥルガン', 'species' => 'machine', 'profile' => 'brute', 'art' => '炉心を胸に据えた巨大な鋳鉄兵。下半身は重厚、腕は大槌。光は炉口にだけ絞る。'],
        ],
    ],
    'library' => [
        'name' => '禁書の回廊', 'description' => '読まれることを拒む書物が、訪問者の名を記録する。',
        'effects' => ['stat_mag', 'killer_mage', 'killer_demon', 'special_thrift'],
        'enemies' => [
            ['name' => '羊皮紙の蛾', 'species' => 'insect', 'profile' => 'swift', 'art' => '文字のかすれた羊皮紙を翅にした大蛾。虫としての厚い胴体と触角を残す。'],
            ['name' => '封蝋の這い魔', 'species' => 'slime', 'profile' => 'guard', 'art' => '朱色の封蝋が溶けて這う魔物。背面に押印の凹凸、短い蝋の指が地面をつかむ。'],
            ['name' => '書架背負いの亡者', 'species' => 'undead', 'profile' => 'brute', 'art' => '小さな木製書架を背負う骸骨。鎖で束ねた本、折れた膝、引きずる重い足。'],
            ['name' => '青墨の写字魔', 'species' => 'mage', 'profile' => 'mage', 'art' => '青黒いインクの指を持つ写字僧。顔は空白の紙、羽ペンの束と染みた長衣。'],
            ['name' => '閉架の司書モルディア', 'species' => 'mage', 'profile' => 'mage', 'art' => '鍵束を持つ背の高い司書の怪異。閉じた本の仮面、厚い衣の裾、片腕に鎖付き索引。'],
        ],
    ],
    'observatory' => [
        'name' => '砕星の観測庭', 'description' => '崩れた天球儀の下を、忘れられた星の軌道が巡る。',
        'effects' => ['stat_agi', 'stat_luk', 'killer_flying', 'killer_spirit', 'special_critical', 'special_opener'],
        'enemies' => [
            ['name' => '環軌道コウモリ', 'species' => 'flying', 'profile' => 'swift', 'art' => '真鍮の観測環を首に掛けたコウモリ。翼膜に星点、足を見せた低い浮遊姿勢。'],
            ['name' => '星砂トカゲ', 'species' => 'beast', 'profile' => 'swift', 'art' => '濃紺の背に星砂が散る太いトカゲ。角ばった尾と地面を踏む四肢を大きく描く。'],
            ['name' => '欠月の測量機', 'species' => 'machine', 'profile' => 'guard', 'art' => '三脚で歩く古い測量機。欠けた月形の照準器、歯車の関節、単眼レンズ。'],
            ['name' => '星図の迷い子', 'species' => 'spirit', 'profile' => 'mage', 'art' => '巻いた星図を抱える小さな精霊。紙のフードから二つの光、足元に浮く小石。'],
            ['name' => '天球竜アストラグ', 'species' => 'dragon', 'profile' => 'balanced', 'art' => '天球儀の環を角と背に持つ太い竜。群青の鱗、翼膜の星図、重みのある後脚。'],
        ],
    ],
    'tomb' => [
        'name' => '無名の王墓', 'description' => '墓碑の名前だけが削り取られた、最奥の王墓。',
        'effects' => ['stat_all', 'killer_undead', 'killer_dragon', 'special_last_stand'],
        'enemies' => [
            ['name' => '冠片の骸兵', 'species' => 'undead', 'profile' => 'balanced', 'art' => '折れた王冠の欠片を兜に刺した骸骨兵。欠けた短剣、古い帯、頑丈な脛当て。'],
            ['name' => '香炉抱きの墓鬼', 'species' => 'demon', 'profile' => 'mage', 'art' => '両腕で石の香炉を抱える丸い鬼。煙は背にまとまり、太い脚と垂れた耳を見せる。'],
            ['name' => '黒棺ミミック', 'species' => 'demon', 'profile' => 'brute', 'art' => '短い脚で立つ黒い棺。蓋の隙間から牙、赤い内張り、擦り切れた金の縁取り。'],
            ['name' => '王墓の近衛霊', 'species' => 'spirit', 'profile' => 'guard', 'art' => '空洞の甲冑に宿る近衛の霊。大盾と折れた槍、兜の奥の青白い灯り。'],
            ['name' => '葬冠王ヴェルザグ', 'species' => 'undead', 'profile' => 'balanced', 'art' => '葬送の王冠を被る重装の骸王。消された紋章の大剣、石棺片の肩甲、厚い足鎧。'],
        ],
    ],
];

$depthBosses = (static fn () => require __DIR__.'/nameless_ruin_bosses.php')();
foreach ($zones as $key => &$zone) {
    $zone['bosses'] = array_map(static fn (array $boss): array => isset($boss['name']) ? $boss : array_merge($zone['enemies'][4], $boss), $depthBosses[$key]);
}
unset($zone);

// 定義の出土地を反映し、新種が抽選対象から漏れないようにする。
foreach ((static fn () => require __DIR__.'/nameless_relic_effects.php')() as $key => $effect) {
    if (! in_array($key, $zones[$effect['zone']]['effects'], true)) {
        $zones[$effect['zone']]['effects'][] = $key;
    }
}
return $zones;
