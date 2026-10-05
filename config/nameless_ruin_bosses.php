<?php

// ローカル遺跡の深度別ボス。最奥の名前・種族・絵は既存定義を引き継ぐ。
// 技の数値は既存PvEの強撃、鎧砕き、連撃、魔法攻撃、予兆突進を使用する。
$actions = [
    'strike' => ['action_type' => 'strong_strike', 'power_percent' => 150],
    'break' => ['action_type' => 'def_down', 'effect_percent' => 20, 'duration_turns' => 3],
    'combo' => ['action_type' => 'multi_hit', 'power_percent' => 80, 'hit_count' => 2],
    'spell' => ['action_type' => 'magical', 'power_percent' => 150],
    'charge' => ['action_type' => 'charge', 'power_percent' => 200, 'cooldown_turns' => 4, 'can_use_on_first_turn' => false, 'is_telegraphed' => true, 'telegraph_turns' => 1, 'can_be_guarded' => true, 'guard_reduction_rate' => .50],
    'ritual' => ['action_type' => 'magical', 'power_percent' => 150, 'cooldown_turns' => 4, 'can_use_on_first_turn' => false, 'is_telegraphed' => true, 'telegraph_turns' => 1, 'can_be_guarded' => true, 'guard_reduction_rate' => .50],
];

$boss = static fn (string $name, string $species, string $profile, string $art, string $technique, string $action, string $trait, string $counter, array $relics): array => compact('name', 'species', 'profile', 'art', 'technique', 'action', 'trait', 'counter', 'relics');
$final = static fn (string $technique, string $action, string $trait, string $counter, array $relics): array => compact('technique', 'action', 'trait', 'counter', 'relics');

$zones = [
    'sand' => [
        $boss('碑門の衛士ガルム', 'machine', 'balanced', '古い参道の石門を守る小柄で頑丈な青銅の衛士。石碑形の胸甲と短い石槌、緑青の目。', '碑槌の強打', 'strike', '物理の強打を使う、攻守の均衡がよい衛士。', '防御を高めて物理攻撃を受け止める。機械への特攻・耐性も有効。', ['stat_def', 'killer_machine', 'resist_machine']),
        $boss('封砂の甲王ケプリ', 'insect', 'guard', '巨大な青銅色のスカラベ王。砂岩の封印板を背負い、朱色の封泥で飾られた厚い甲殻と大きな鋏。', '封甲砕き', 'break', '防御が高く、攻撃でこちらの防御を下げる甲虫。', '魔法や物理貫通で硬い甲殻を抜く。防御だけに頼らず昆虫耐性で備える。', ['special_convert_magical', 'special_pierce_physical', 'resist_insect']),
        $boss('砂走りの牙王ラグル', 'beast', 'swift', '砂色の毛皮を持つ大きな遺跡ジャッカル。青銅の牙飾り、力強い四脚、渦状の砂をまとった短い尾。', '双牙襲', 'combo', '素早く、二連撃でHPを削る獣。守りは薄い。', 'HPと獣耐性で連撃に備え、獣特攻で早く倒す。初護の楔は最初の攻撃行動だけなので、以後の連撃には耐性で備える。', ['stat_hp', 'resist_beast', 'killer_beast']),
        $final('門柱崩し', 'charge', '攻撃とHPが高く、予兆の後に重い物理の突進を放つ。', 'HP・防御・獣耐性を確保する。予兆中に倒せば大技は不発。初撃軽減だけに頼らない。', ['stat_hp', 'resist_beast', 'special_mirror_guard']),
    ],
    'water' => [
        $boss('水門の番獣トルガ', 'aquatic', 'balanced', '水門の青い陶板を背負う太いイモリ型の番獣。淡い腹、苔むす青銅の首輪、四脚と幅広い尾。', '水門の尾撃', 'strike', '尾による物理の強打を使う水棲の番獣。', '防御と水棲耐性を高め、水棲特攻で押し切る。', ['stat_def', 'resist_aquatic', 'killer_aquatic']),
        $boss('沈鐘の祭司ヴェルネ', 'undead', 'mage', '水に沈んだ釣鐘を冠にした亡霊の祭司。濡れた藍色の祭服、白骨の手、青い灯りの小さな鐘杖。', '沈鐘の響き', 'spell', '魔法で攻撃し、精神が高い。物理への守りは薄い。', '精神と不死耐性で魔法を軽減し、物理攻撃や不死特攻を使う。', ['stat_spr', 'resist_undead', 'killer_undead']),
        $boss('鎖潮の海蛇サルヴァ', 'aquatic', 'swift', '水車の青銅鎖を背びれに絡ませた太い海蛇。深い青緑の鱗、二本の牙、蛇体を低い輪にまとめた姿。', '鎖潮の双牙', 'combo', '高い敏捷から二連撃を放つ、守りの薄い海蛇。', 'HPと水棲耐性で連撃を受け、水棲特攻で長期戦を避ける。', ['stat_hp', 'resist_aquatic', 'killer_aquatic']),
        $final('貯水殿の大跳躍', 'charge', 'HPと防御が高く、予兆の後に重い物理の跳躍を放つ。', '魔法か貫通で硬い守りを抜く。水棲耐性とHPを確保し、予兆中の決着を狙う。', ['special_convert_magical', 'resist_aquatic', 'special_mirror_guard']),
    ],
    'forge' => [
        $boss('鋳場の槌兵ボルド', 'machine', 'balanced', '冷えた鋳鉄と古い煉瓦でできた小型の工房兵。片手に短い鍛冶槌、煤のついた胸炉、重い足。', '鋳槌の強打', 'strike', '槌による物理の強打を使う機械兵。', '防御と機械耐性で備え、機械特攻で倒す。', ['stat_def', 'resist_machine', 'killer_machine']),
        $boss('赤炉の祈祷師イグナ', 'mage', 'mage', '炉灰色の厚い祭衣をまとう犬頭の老いた祈祷師。赤い炉火を封じた鉄の香炉杖、煤けた耐熱手袋。', '赤炉の火祈り', 'spell', '高い魔力で攻撃する。精神が高い一方、防御は低い。', '精神と魔法使い耐性で備え、物理攻撃や魔法使い特攻を使う。', ['stat_spr', 'resist_mage', 'killer_mage']),
        $boss('鉄鎖の破砕獣ガラン', 'beast', 'brute', '錆びた鋳型の鎧と太い鉄鎖をまとう巨大な猪。厚い前脚、鉄の牙、首に重い破砕錘。', '破砕の連牙', 'combo', '攻撃とHPが高く、重い二連撃を使う。敏捷は低い。', 'HP・防御・獣耐性で連撃に備える。獣特攻で高いHPを削る。', ['stat_def', 'resist_beast', 'killer_beast']),
        $final('炉心の大槌', 'charge', '攻撃とHPが高く、予兆の後に重い物理の大槌を振るう。', '防御と機械耐性で備える。HPを十分に回復し、予兆中に倒せる火力も用意する。', ['stat_def', 'resist_machine', 'special_mirror_guard']),
    ],
    'library' => [
        $boss('封書の衛士パピル', 'machine', 'balanced', '羊皮紙の封書と木の書板を胸に抱える小柄な書庫人形。青銅の関節、赤い封蝋、短い栞形の剣。', '書板の強打', 'strike', '書板による物理の強打を使う書庫人形。', '防御と機械耐性で強打を受け、機械特攻で攻める。', ['stat_def', 'resist_machine', 'killer_machine']),
        $boss('墨殻の書庫蟲ビブロ', 'insect', 'guard', '古い革装丁を甲殻に持つ大きな甲虫。青墨の光沢、赤い封蝋の紋、紙束状の太い脚と鋏。', '装丁砕き', 'break', '硬い甲殻を持ち、攻撃でこちらの防御を下げる。', '魔法や物理貫通で甲殻を抜き、昆虫耐性で防御低下中にも備える。', ['special_convert_magical', 'special_pierce_physical', 'resist_insect']),
        $boss('索引の影刃シグル', 'undead', 'swift', '索引札を吊るした暗色の外套をまとう骸骨の暗殺者。二本の青銅の短剣、革帯、厚い靴。', '索引の双刃', 'combo', '素早い二連撃を使う不死の剣士。守りは薄い。', 'HPと不死耐性で連撃に備え、不死特攻で短期決着を狙う。', ['stat_hp', 'resist_undead', 'killer_undead']),
        $final('閉架の禁呪', 'ritual', '魔法で攻撃し、予兆を伴う禁呪を使う。精神が高く防御は低い。', '精神と魔法使い耐性で魔法を受け、物理攻撃や特攻で予兆中の決着を狙う。', ['stat_spr', 'resist_mage', 'killer_mage']),
    ],
    'observatory' => [
        $boss('観測塔の翼番オルニ', 'flying', 'balanced', '真鍮の観測環を胸に掛けた太い夜鳥の番人。群青の羽、白い眉、頑丈な足と小さく畳んだ翼。', '観測環の翼撃', 'strike', '翼による物理の強打を使う飛行の番人。', '防御と飛行耐性を高め、飛行特攻で倒す。', ['stat_def', 'resist_flying', 'killer_flying']),
        $boss('月環の守機セレド', 'machine', 'guard', '欠月形の大盾を持つ低重心の観測人形。青銅の天球儀の胴、三本の太い脚、単眼レンズ。', '月環砕き', 'break', '防御が高く、攻撃でこちらの防御を下げる機械。', '魔法や物理貫通で守りを抜く。機械耐性で防御低下を補う。', ['special_convert_magical', 'special_pierce_physical', 'resist_machine']),
        $boss('星詠みの幽師ルミア', 'spirit', 'mage', '星図を厚い長衣に縫い込んだ精霊の星詠み。金の観測環と群青のフード、青白い目、短い天球杖。', '星詠みの光', 'spell', '魔法で攻撃し、精神が高い精霊。物理への守りは薄い。', '精神と精霊耐性を高め、物理攻撃や精霊特攻で攻める。', ['stat_spr', 'resist_spirit', 'killer_spirit']),
        $final('天球の星息', 'ritual', '普段は物理で攻撃し、予兆の後は魔法の星息を放つ。', '防御と精神の両方、または竜耐性で備える。竜特攻で予兆中の決着を狙う。', ['stat_spr', 'resist_dragon', 'killer_dragon']),
    ],
    'tomb' => [
        $boss('墓門の骸将ヴァロス', 'undead', 'balanced', '古い王墓の門を守る太い骨格の骸骨将軍。欠けた石兜、青銅の短い槍、黒い大盾、厚い足鎧。', '墓槍の強打', 'strike', '物理の強打を使う不死の将軍。', '防御と不死耐性で強打に備え、不死特攻で攻める。', ['stat_def', 'resist_undead', 'killer_undead']),
        $boss('石棺の鎧鬼ドルム', 'demon', 'guard', '削られた石棺を分厚い鎧にした丸い墓鬼。石蓋形の盾、欠けた角、黒い皮膚、頑丈な脚。', '棺鎧砕き', 'break', 'HPと防御が高く、攻撃でこちらの防御を下げる悪魔。', '魔法や物理貫通で石棺の鎧を抜き、悪魔耐性で備える。', ['special_convert_magical', 'special_pierce_physical', 'resist_demon']),
        $boss('葬灯の導師エルガ', 'mage', 'mage', '葬送の灯を持つ顔を隠した墓守の導師。白骨色と黒の重い祭衣、青い灯りの香炉、赤い封蝋。', '葬灯の呪光', 'spell', '高い魔力で攻撃する導師。精神が高く、防御は低い。', '精神と魔法使い耐性を高め、物理攻撃や魔法使い特攻で攻める。', ['stat_spr', 'resist_mage', 'killer_mage']),
        $final('葬冠の断頭剣', 'charge', '攻守の均衡がよく、予兆の後に重い物理の大剣を振るう。', 'HP・防御・不死耐性で備える。不死特攻で予兆中の決着を狙い、致死対策も用意する。', ['stat_hp', 'resist_undead', 'special_survive']),
    ],
];

foreach ($zones as $key => &$bosses) {
    foreach ($bosses as $index => &$boss) {
        $boss['min_depth'] = [1, 25, 50, 75][$index];
        $boss['max_depth'] = [24, 49, 74, 100][$index];
        $boss['stage'] = ['入口の守護者', '中層の守護者', '深層の守護者', '遺跡の主'][$index];
        $boss['action'] = $actions[$boss['action']] + ['name' => $boss['technique']];
        if ($index < 3) {
            $boss['image'] = 'images/enemy/ruins/ruins_boss_'.$key.'_0'.($index + 1).'.webp';
        }
    }
    unset($boss);
}
unset($bosses);

return $zones;
