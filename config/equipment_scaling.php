<?php

return [
    // 武器・防具・装飾品の全固定能力値と保存済み銘補正は、データ上で一度だけ8倍化済み。
    // 武器の攻撃・魔力、防具の防御・精神はそれぞれの実効式で使い、装飾品は固定値をそのまま加算する。
    'weapon_offense_scale_version' => 2,
    'armor_performance_scale_version' => 2,
    'accessory_performance_scale_version' => 2,
    'accessory_performance_scale_factor' => 8,
];
