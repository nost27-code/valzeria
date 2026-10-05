<?php

// 保存済み effect_key は変更しない。数値は nameless_relics のランク別曲線に集約する。
$effects = [];
$add = static function (string $key, string $name, string $category, string $target, string $description, string $zone, string $profile, array $extra = []) use (&$effects): void {
    $effects[$key] = compact('key', 'name', 'category', 'target', 'description', 'zone', 'profile') + $extra;
};
$stats = ['hp' => '生命', 'mp' => '霊泉', 'str' => '剛力', 'def' => '堅牢', 'mag' => '魔導', 'spr' => '祈祷', 'agi' => '疾風', 'luk' => '豪運', 'all' => '調律'];
$statZones = ['hp' => 'sand', 'mp' => 'water', 'str' => 'forge', 'def' => 'sand', 'mag' => 'library', 'spr' => 'water', 'agi' => 'observatory', 'luk' => 'observatory', 'all' => 'tomb'];
foreach ($stats as $target => $name) {
    $add('stat_'.$target, $name.'の遺物', 'stat', $target, '遺物を除いた装備込み能力に割合で加算する', $statZones[$target], $target === 'all' ? 'all' : 'single', ['stats' => $target === 'all' ? array_keys(array_diff_key($stats, ['all' => 0])) : [$target]]);
}
foreach ([
    'valor' => ['武勇の紋章', ['str', 'def'], 'forge'], 'dance' => ['剣舞の紋章', ['str', 'agi'], 'observatory'],
    'polarity' => ['双極の紋章', ['str', 'mag'], 'library'], 'ward' => ['魔護の紋章', ['mag', 'spr'], 'library'],
    'stars' => ['星導の紋章', ['mag', 'agi'], 'observatory'], 'fortress' => ['城塞の紋章', ['hp', 'def'], 'sand'],
    'channel' => ['霊導の紋章', ['mp', 'mag'], 'water'], 'night' => ['夜渡の紋章', ['agi', 'luk'], 'observatory'],
    'fortune' => ['幸護の紋章', ['luk', 'spr'], 'water'],
] as $target => [$name, $targets, $zone]) {
    $add('compound_'.$target, $name, 'compound', $target, '二つの能力を割合で増やす', $zone, 'compound', ['stats' => $targets]);
}
foreach ([
    'haste' => ['疾駆の羽根', 'agi', 'def', 'observatory'], 'weight' => ['剛重の楔', 'def', 'agi', 'sand'],
    'gamble' => ['賭命の骰子', 'luk', 'hp', 'tomb'],
] as $target => [$name, $up, $down, $zone]) {
    $add('trade_'.$target, $name, 'trade', $target, '能力を伸ばす代わりに別の能力が下がる', $zone, 'trade', ['stats' => [$up], 'negative_stats' => [$down], 'negative_profile' => 'trade_cost']);
}
$labels = (require __DIR__.'/enemy_species.php')['labels'];
$zones = ['beast' => 'sand', 'insect' => 'sand', 'aquatic' => 'water', 'slime' => 'water', 'machine' => 'forge', 'soldier' => 'forge', 'mage' => 'library', 'demon' => 'library', 'flying' => 'observatory', 'spirit' => 'observatory', 'undead' => 'tomb', 'dragon' => 'tomb'];
$brandNames = ['beast' => '獣刻', 'undead' => '死刻', 'dragon' => '竜刻', 'demon' => '魔刻', 'aquatic' => '潮刻', 'flying' => '翼刻', 'insect' => '蟲刻', 'machine' => '機刻', 'slime' => '粘刻', 'soldier' => '人刻', 'mage' => '術刻', 'spirit' => '霊刻'];
foreach ($labels as $target => $label) {
    $add('killer_'.$target, $label.'特攻の遺物', 'killer', $target, $label.'への直接与ダメージが増える', $zones[$target], 'killer', ['killers' => [$target]]);
    $add('resist_'.$target, $label.'耐性の遺物', 'resist', $target, $label.'からの直接被ダメージを軽減する', $zones[$target], 'resist', ['resists' => [$target]]);
    $add('brand_'.$target, $brandNames[$target].'の釘', 'brand', $target, '戦闘開始時、敵に'.$label.'の刻印を追加する。装備・遺物の特攻と耐性だけが参照し、職業技の種族判定には使わない', $zones[$target], 'brand', ['exclusive_group' => 'brand']);
}
foreach ([
    'exorcism' => ['鎮魔の紋章', ['undead', 'demon'], [], 'tomb'], 'hunt' => ['野狩の紋章', ['beast', 'insect'], [], 'sand'],
    'boundary' => ['境界の護符', [], ['mage', 'spirit'], 'library'], 'ocean' => ['深海の護符', [], ['aquatic', 'slime'], 'water'],
    'dragon' => ['竜狩の紋章', ['dragon'], ['dragon'], 'tomb'],
] as $target => [$name, $killers, $resists, $zone]) {
    $add('species_'.$target, $name, 'species', $target, '複数の特攻・耐性を持つ（単体遺物より各効果は小さい）', $zone, 'species', compact('killers', 'resists'));
}
foreach ([
    'dragon' => ['竜骸の仮面', ['str', 'hp'], [], 'tomb'], 'beast' => ['獣王の牙', ['str', 'agi'], [], 'sand'],
    'machine' => ['機巧の心核', ['def', 'spr'], ['agi'], 'forge'], 'spirit' => ['幽魂の灯', ['mag', 'spr'], [], 'library'],
] as $target => [$name, $targets, $negative, $zone]) {
    $add('form_'.$target, $name, 'form', $target, '自分の種族を'.$labels[$target].'に変え、能力を増やす。対応する特攻を受ける', $zone, 'form', ['stats' => $targets, 'negative_stats' => $negative, 'negative_profile' => 'trade_cost', 'exclusive_group' => 'form']);
}
foreach ([
    'critical' => ['狙眼のレンズ', '会心率が上がる（既存の会心率上限内）', 'observatory'],
    'thrift' => ['節魔の環', '戦技の固定SP消費を軽減する（最低1・威力連動分は対象外）', 'library'],
    'opener' => ['先駆の砂時計', '最初にダメージを与える攻撃行動を強化する', 'observatory'],
    'first_guard' => ['初護の楔', '最初にダメージを受ける攻撃行動を軽減する', 'sand'],
    'finisher' => ['終撃の楔', '敵のHPが30%以下のとき直接与ダメージが増える', 'forge'],
    'last_stand' => ['背水の冠片', '自分のHPが30%以下のとき直接被ダメージを軽減する', 'tomb'],
    'victory_hp' => ['息吹の水珠', 'PvE勝利後に最大HPに応じて回復する（最低1・最大HPまで）', 'water'],
    'victory_sp' => ['澄明の水珠', 'PvE勝利後に最大SPに応じて回復する（最低1・最大SPまで）', 'water'],
    'drain' => ['血杯の欠片', '通常攻撃で実際に減らしたHPの一部を回復する（盾・過剰ダメージを除く）', 'tomb'],
    'echo_sp' => ['反響の環', '通常攻撃が命中した行動で最大SPの一部を一度回復する', 'water'],
    'pierce_physical' => ['砕壁の鏃', '物理攻撃で守りの一部を無視する（他の貫通と合わせて50%まで）', 'forge'],
    'pierce_magical' => ['透魂の針', '魔法攻撃で守りの一部を無視する（他の貫通と合わせて50%まで）', 'library'],
    'convert_physical' => ['剣化の印', '通常攻撃を攻撃依存の物理攻撃に変える（SP不要・戦技は変えない）', 'forge'],
    'convert_magical' => ['星化の印', '通常攻撃を魔力依存の魔法攻撃に変える（SP不要・戦技は変えない）', 'observatory'],
    'chase' => ['追響の刃', '通常攻撃の命中時、確率で実HPダメージの30%を追撃する（行動回数は増えない）', 'observatory'],
    'counter' => ['返刃の留め具', '直接攻撃を受けた行動につき一度、確率で実HPダメージの25%を返す', 'forge'],
    'survive' => ['不屈の石片', '戦闘中一度、致死ダメージを耐え最大HPの一部を残す（既存の根性と共用・自傷は除く）', 'sand'],
    'mirror_guard' => ['鏡守の盾片', '直接被ダメージを軽減し、軽減した分の25%を行動につき一度だけ返す', 'sand'],
    'blood_pact' => ['背水の契約', '戦闘中のHP回復を失う代わりに直接与ダメージが増える（勝利後回復は有効）', 'tomb'],
] as $target => [$name, $description, $zone]) {
    $extra = match ($target) {
        'convert_physical' => ['exclusive_group' => 'conversion', 'stats' => ['str']],
        'convert_magical' => ['exclusive_group' => 'conversion', 'stats' => ['mag']],
        default => [],
    };
    $add('special_'.$target, $name, 'special', $target, $description, $zone, $target, $extra);
}
$add('counter_brand_guard', '断刻の護符', 'countermeasure', 'brand_guard', '種族特攻による追加ダメージだけを軽減する（変身の弱点にも有効）', 'library', 'brand_guard');
$add('counter_cleanse', '浄刻の鏡', 'countermeasure', 'cleanse', '最初の自分の行動開始時に敵の刻印を必ず除く。自分の変身は残る。精神も増える', 'water', 'cleanse', ['stats' => ['spr']]);

return $effects;
