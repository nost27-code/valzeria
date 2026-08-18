<?php

/**
 * 戦技v2 公式プリセット。
 *
 * すべて同一系譜5枚で完結する明示構成とし、未習得技の自動差し替えは行わない。
 * skill key は「job_id:learn_rank」。表示名・実ID・使用可否は現行masterから解決する。
 */

$variant = static fn (array $skills, array $conditions = []): array => [
    'skills' => $skills,
    'conditions' => $conditions,
];

$counterplay = 'opponent_ultimate_preparing';
$fieldPresent = 'field_present';

return [
    'counter' => [
        'finisher' => [
            'name' => '決着型',
            'build_name' => '雪月一閃',
            'purpose' => '短期戦・PvP',
            'description' => '剣勢を速く12まで蓄え、攻撃強化から高威力奥義へつなげます。',
            'tags' => ['短期向け', '奥義優先'],
            'variants' => [
                'advanced' => $variant(['28:1', '13:1', '11:1', '13:5', '28:9']),
                'super' => $variant(['50:1', '28:1', '11:1', '50:5', '50:9']),
                'crown' => $variant(['28:1', '11:1', '50:1', '50:5', '60:9']),
            ],
        ],
        'cycle' => [
            'name' => '循環型',
            'build_name' => '剣勢連環',
            'purpose' => '汎用・長期戦',
            'description' => '受け流しの構えを織り交ぜ、始動と連携を循環させて攻め続けます。',
            'tags' => ['長期向け', '反撃循環'],
            'variants' => [
                'advanced' => $variant(['28:1', '1:5', '1:1', '13:5', '28:9']),
                'super' => $variant(['50:1', '1:5', '28:1', '50:5', '50:9']),
                'crown' => $variant(['11:1', '13:1', '11:5', '50:5', '1:9']),
            ],
        ],
        'tactical' => [
            'name' => '対策型',
            'build_name' => '王冠迎撃',
            'purpose' => '物理ボス・PvP',
            'description' => '見切りで受け流しを狙い、大技軽減と反撃で押し返します。',
            'tags' => ['大技対策', '物理対策'],
            'variants' => [
                'advanced' => $variant(['28:1', '28:5', '1:1', '13:5', '28:9'], ['28:5' => $counterplay]),
                'super' => $variant(['50:1', '28:5', '28:1', '50:5', '50:9'], ['28:5' => $counterplay]),
                'crown' => $variant(['1:1', '60:1', '28:5', '60:5', '60:9'], ['28:5' => $counterplay]),
            ],
        ],
    ],

    'pierce' => [
        'finisher' => [
            'name' => '決着型',
            'build_name' => '天穿速攻',
            'purpose' => 'ボス・PvP',
            'description' => '構えと連携条件をそろえ、完成した貫通奥義を最短で通します。',
            'tags' => ['短期向け', '高貫通'],
            'variants' => [
                'advanced' => $variant(['32:1', '2:1', '16:1', '32:5', '32:9']),
                'super' => $variant(['52:1', '32:1', '2:1', '52:5', '52:9']),
                'crown' => $variant(['2:1', '16:1', '62:1', '62:5', '62:9']),
            ],
        ],
        'cycle' => [
            'name' => '循環型',
            'build_name' => '蒼天連環',
            'purpose' => '長期ボス',
            'description' => '構えを維持しながら、二段攻撃と防御無視を繰り返します。',
            'tags' => ['長期向け', '構え維持'],
            'variants' => [
                'advanced' => $variant(['32:1', '32:5', '2:1', '2:5', '32:9']),
                'super' => $variant(['52:1', '52:5', '2:1', '2:5', '52:9']),
                'crown' => $variant(['32:1', '52:1', '2:5', '52:5', '52:9']),
            ],
        ],
        'tactical' => [
            'name' => '対策型',
            'build_name' => '大技穿ち',
            'purpose' => '予告大技・PvP',
            'description' => '大技を止めず、準備中の隙へ高威力・高貫通の一撃を差し込みます。',
            'tags' => ['大技対策', '先制圧力'],
            'variants' => [
                'advanced' => $variant(['32:1', '32:5', '16:1', '2:5', '32:9'], ['32:5' => $counterplay]),
                'super' => $variant(['52:1', '32:5', '16:1', '52:5', '52:9'], ['32:5' => $counterplay]),
                'crown' => $variant(['16:1', '62:1', '32:5', '45:5', '32:9'], ['32:5' => $counterplay]),
            ],
        ],
    ],

    'hunt' => [
        'finisher' => [
            'name' => '決着型',
            'build_name' => '終葬追跡',
            'purpose' => 'PvP・ボス',
            'description' => '標的印を作って維持し、終葬射に必要な2段階を残したまま奥義へ進みます。',
            'tags' => ['奥義優先', '標的印'],
            'variants' => [
                'advanced' => $variant(['37:1', '34:1', '17:1', '37:5', '37:9']),
                'super' => $variant(['54:1', '37:1', '17:1', '37:5', '54:9']),
                'crown' => $variant(['54:1', '64:1', '37:1', '37:5', '64:9']),
            ],
        ],
        'cycle' => [
            'name' => '循環型',
            'build_name' => '幻影連環',
            'purpose' => '通常・長期戦',
            'description' => '強化と能力低下を交互に回し、重い標的印条件に依存せず戦います。',
            'tags' => ['長期向け', '安定型'],
            'variants' => [
                'advanced' => $variant(['3:1', '3:5', '17:1', '34:5', '17:9']),
                'super' => $variant(['54:1', '37:5', '17:1', '34:5', '54:9']),
                'crown' => $variant(['3:1', '34:1', '3:5', '34:5', '34:9']),
            ],
        ],
        'tactical' => [
            'name' => '対策型',
            'build_name' => '影牢封殺',
            'purpose' => '予告大技・PvP',
            'description' => '標的印を作り、大技遅延または奥義準備の中断から封技奥義へつなげます。',
            'tags' => ['大技対策', '直接中断'],
            'variants' => [
                'advanced' => $variant(['17:1', '17:5', '37:1', '37:5', '37:9']),
                'super' => $variant(['54:1', '54:5', '37:1', '37:5', '54:9'], ['54:5' => $counterplay]),
                'crown' => $variant(['54:1', '64:1', '54:5', '37:5', '54:9'], ['54:5' => $counterplay]),
            ],
        ],
    ],

    'aim' => [
        'finisher' => [
            'name' => '決着型',
            'build_name' => '星穿連射',
            'purpose' => '短期戦・ボス',
            'description' => '照準と有利な攻魔経路を整え、多段奥義を高い命中で通します。',
            'tags' => ['短期向け', '多段攻撃'],
            'variants' => [
                'advanced' => $variant(['35:1', '22:1', '4:1', '18:5', '4:9']),
                'super' => $variant(['55:1', '35:1', '22:1', '18:5', '55:9']),
                'crown' => $variant(['22:1', '35:1', '65:1', '18:5', '65:9']),
            ],
        ],
        'cycle' => [
            'name' => '循環型',
            'build_name' => '追尾射撃',
            'purpose' => '長期ボス',
            'description' => '有利な物理・魔法経路と追尾座標を使い、命中とSP圧力を維持します。',
            'tags' => ['長期向け', '命中管理'],
            'variants' => [
                'advanced' => $variant(['35:1', '22:5', '22:1', '35:5', '35:9']),
                'super' => $variant(['55:1', '22:5', '22:1', '55:5', '55:9']),
                'crown' => $variant(['18:1', '65:1', '22:5', '65:5', '55:9']),
            ],
        ],
        'tactical' => [
            'name' => '対策型',
            'build_name' => 'SP封鎖',
            'purpose' => '高回避・高敏捷・魔法敵',
            'description' => '命中を確保し、高回避・高敏捷・高精神の相手へ対応します。',
            'tags' => ['大技対策', 'SP圧力'],
            'variants' => [
                'advanced' => $variant(['4:1', '4:5', '35:1', '35:5', '35:9'], ['4:5' => $counterplay]),
                'super' => $variant(['4:1', '4:5', '55:1', '55:5', '55:9'], ['4:5' => $counterplay]),
                'crown' => $variant(['4:1', '18:1', '4:5', '35:5', '35:9'], ['4:5' => $counterplay]),
            ],
        ],
    ],

    'break' => [
        'finisher' => [
            'name' => '決着型',
            'build_name' => '覇王崩拳',
            'purpose' => 'ボス・PvP',
            'description' => '崩し印を積み、条件をそろえた高火力奥義で決着します。',
            'tags' => ['高火力', '準備型'],
            'variants' => [
                'advanced' => $variant(['33:1', '5:1', '21:1', '33:5', '33:9']),
                'super' => $variant(['58:1', '5:1', '21:1', '58:5', '58:9']),
                'crown' => $variant(['68:1', '58:1', '5:1', '58:5', '58:9']),
            ],
        ],
        'cycle' => [
            'name' => '循環型',
            'build_name' => '残心連打',
            'purpose' => '長期ボス',
            'description' => '回復しながら崩し印を積み、印を失っても残心から立て直します。',
            'tags' => ['長期向け', '回復'],
            'variants' => [
                'advanced' => $variant(['33:1', '33:5', '21:1', '21:5', '33:9']),
                'super' => $variant(['58:1', '58:5', '21:1', '33:5', '58:9']),
                'crown' => $variant(['21:1', '33:1', '5:5', '68:5', '33:9']),
            ],
        ],
        'tactical' => [
            'name' => '対策型',
            'build_name' => '構え破り',
            'purpose' => '予告大技・魔法敵',
            'description' => '崩し印を使って構え・装填・障壁を壊し、精神参照攻撃で追撃します。',
            'tags' => ['大技対策', '準備破壊'],
            'variants' => [
                'advanced' => $variant(['33:1', '33:5', '21:1', '21:5', '33:9'], ['33:5' => $counterplay]),
                'super' => $variant(['58:1', '33:5', '21:1', '21:5', '58:9'], ['33:5' => $counterplay]),
                'crown' => $variant(['68:1', '33:1', '33:5', '21:5', '68:9'], ['33:5' => $counterplay]),
            ],
        ],
    ],

    'field' => [
        'finisher' => [
            'name' => '決着型',
            'build_name' => '星冠上書き',
            'purpose' => 'ボス',
            'description' => '場を繰り返し展開・上書きし、その履歴で奥義を強化します。',
            'tags' => ['奥義優先', '場の上書き'],
            'variants' => [
                'advanced' => $variant(['6:1', '23:1', '29:1', '29:5', '29:9']),
                'super' => $variant(['6:1', '23:1', '53:1', '53:5', '53:9']),
                'crown' => $variant(['6:1', '23:1', '46:1', '63:5', '63:9']),
            ],
        ],
        'cycle' => [
            'name' => '循環型',
            'build_name' => '星天固定',
            'purpose' => '長期ボス',
            'description' => '場を延長・保護してから、星天固定で戦場を長く支配します。',
            'tags' => ['長期向け', '場の維持'],
            'variants' => [
                'advanced' => $variant(['29:1', '23:5', '46:1', '46:5', '46:9'], ['23:5' => $fieldPresent]),
                'super' => $variant(['53:1', '23:5', '46:1', '53:5', '53:9'], ['23:5' => $fieldPresent]),
                'crown' => $variant(['53:1', '46:1', '23:5', '46:5', '53:9'], ['23:5' => $fieldPresent]),
            ],
        ],
        'tactical' => [
            'name' => '対策型',
            'build_name' => '封式聖堂',
            'purpose' => '予告大技・耐久',
            'description' => '静寂の場で相手の資源獲得を抑え、浄化と軽減で立て直します。',
            'tags' => ['大技対策', '立て直し'],
            'variants' => [
                'advanced' => $variant(['29:1', '29:5', '6:1', '46:5', '29:9']),
                'super' => $variant(['29:1', '53:5', '53:1', '29:5', '24:9'], ['53:5' => $counterplay]),
                'crown' => $variant(['24:1', '29:1', '29:5', '53:5', '24:9'], ['53:5' => $counterplay]),
            ],
        ],
    ],

    'guard' => [
        'finisher' => [
            'name' => '決着型',
            'build_name' => '聖冠速成',
            'purpose' => 'ボス・PvP',
            'description' => '守りながら攻撃し、攻防一体の奥義で押し切ります。',
            'tags' => ['奥義優先', '防御'],
            'variants' => [
                'advanced' => $variant(['44:1', '36:1', '15:1', '44:5', '44:9']),
                'super' => $variant(['56:1', '44:1', '36:1', '56:5', '56:9']),
                'crown' => $variant(['10:1', '44:1', '56:1', '44:5', '44:9']),
            ],
        ],
        'cycle' => [
            'name' => '循環型',
            'build_name' => '聖護循環',
            'purpose' => '長期ボス',
            'description' => '小回復・軽減・浄化を循環させ、受けた損傷を継続的に立て直します。',
            'tags' => ['長期向け', '回復'],
            'variants' => [
                'advanced' => $variant(['44:1', '10:5', '36:1', '44:5', '36:9']),
                'super' => $variant(['56:1', '10:5', '36:1', '56:5', '56:9']),
                'crown' => $variant(['7:1', '36:1', '10:5', '66:5', '36:9']),
            ],
        ],
        'tactical' => [
            'name' => '対策型',
            'build_name' => '大技迎撃',
            'purpose' => '予告大技・PvP',
            'description' => '一撃だけの40%軽減と、大技・奥義向けの軽減を使い分けて受け切ります。',
            'tags' => ['大技対策', '大幅軽減'],
            'variants' => [
                'advanced' => $variant(['15:1', '15:5', '44:1', '44:5', '44:9'], ['15:5' => $counterplay]),
                'super' => $variant(['15:1', '15:5', '56:1', '56:5', '56:9'], ['15:5' => $counterplay]),
                'crown' => $variant(['15:1', '66:1', '15:5', '66:5', '66:9'], ['15:5' => $counterplay]),
            ],
        ],
    ],

    'transmute' => [
        'finisher' => [
            'name' => '決着型',
            'build_name' => '爆装錬成',
            'purpose' => '通常・ボス',
            'description' => '能力低下と効果時間の加工を重ね、相手の有利な時間を短くします。',
            'tags' => ['短期向け', '効果時間操作'],
            'variants' => [
                'advanced' => $variant(['26:1', '49:1', '38:1', '26:5', '49:9']),
                'super' => $variant(['26:1', '49:1', '57:1', '26:5', '49:9']),
                'crown' => $variant(['26:1', '49:1', '67:1', '26:5', '49:9']),
            ],
        ],
        'cycle' => [
            'name' => '循環型',
            'build_name' => '霊薬循環',
            'purpose' => '長期ボス',
            'description' => '攻撃を止めず、HP・SP・浄化・能力低下を循環させます。',
            'tags' => ['長期向け', '回復・浄化'],
            'variants' => [
                'advanced' => $variant(['49:1', '25:5', '47:1', '26:5', '47:9']),
                'super' => $variant(['57:1', '25:5', '47:1', '26:5', '47:9']),
                'crown' => $variant(['25:1', '38:1', '25:5', '38:5', '47:9']),
            ],
        ],
        'tactical' => [
            'name' => '対策型',
            'build_name' => 'ミダス封鎖',
            'purpose' => '強化・資源対策',
            'description' => '相手の強化時間と系譜資源獲得を加工し、自分の展開を先に整えます。',
            'tags' => ['大技対策', '資源妨害'],
            'variants' => [
                'advanced' => $variant(['49:1', '49:5', '38:1', '26:5', '49:9'], ['49:5' => $counterplay]),
                'super' => $variant(['57:1', '49:5', '49:1', '57:5', '57:9'], ['49:5' => $counterplay]),
                'crown' => $variant(['67:1', '49:1', '67:5', '26:5', '67:9']),
            ],
        ],
    ],

    'eclipse' => [
        'finisher' => [
            'name' => '決着型',
            'build_name' => '獄炎自傷',
            'purpose' => 'ボス・PvP',
            'description' => '非致死の自傷履歴を蓄え、獄炎奥義の追加威力へ変換します。',
            'tags' => ['高火力', '高リスク'],
            'variants' => [
                'advanced' => $variant(['14:1', '30:1', '19:1', '30:5', '30:9']),
                'super' => $variant(['14:1', '30:1', '51:1', '51:5', '51:9']),
                'crown' => $variant(['14:1', '30:1', '51:1', '51:5', '51:9']),
            ],
        ],
        'cycle' => [
            'name' => '循環型',
            'build_name' => '吸命循環',
            'purpose' => '長期ボス',
            'description' => '攻撃と吸収を繰り返しながら、冥蝕を安定して循環させます。',
            'tags' => ['長期向け', '吸収'],
            'variants' => [
                'advanced' => $variant(['19:1', '19:5', '30:1', '30:5', '30:9']),
                'super' => $variant(['19:1', '19:5', '51:1', '30:5', '51:9']),
                'crown' => $variant(['9:1', '61:1', '9:5', '30:5', '30:9']),
            ],
        ],
        'tactical' => [
            'name' => '対策型',
            'build_name' => '黒冠反転',
            'purpose' => '回復敵・PvP',
            'description' => '大技には反動を課し、回復には反転効果を合わせて立て直しを罰します。',
            'tags' => ['大技対策', '回復対策'],
            'variants' => [
                'advanced' => $variant(['30:1', '30:5', '19:1', '9:5', '30:9'], ['30:5' => $counterplay]),
                'super' => $variant(['51:1', '30:5', '19:1', '51:5', '51:9'], ['30:5' => $counterplay]),
                'crown' => $variant(['30:1', '61:1', '30:5', '61:5', '61:9'], ['30:5' => $counterplay]),
            ],
        ],
    ],

    'command' => [
        'finisher' => [
            'name' => '決着型',
            'build_name' => '王戦速攻',
            'purpose' => 'PvP・ボス',
            'description' => '行動順と発動準備を整え、先攻付きの指揮奥義へ最短で進みます。',
            'tags' => ['短期向け', '先攻'],
            'variants' => [
                'advanced' => $variant(['48:1', '27:1', '12:1', '48:5', '48:9']),
                'super' => $variant(['59:1', '48:1', '27:1', '59:5', '59:9']),
                'crown' => $variant(['48:1', '59:1', '69:1', '48:5', '69:9']),
            ],
        ],
        'cycle' => [
            'name' => '循環型',
            'build_name' => '八陣輪転',
            'purpose' => '長期ボス',
            'description' => '始動・連携・奥義の区分を回し、八陣完成の強化を繰り返し狙います。',
            'tags' => ['長期向け', '区分循環'],
            'variants' => [
                'advanced' => $variant(['48:1', '12:5', '27:1', '27:5', '48:9']),
                'super' => $variant(['59:1', '59:5', '27:1', '27:5', '59:9']),
                'crown' => $variant(['27:1', '59:1', '27:5', '59:5', '59:9']),
            ],
        ],
        'tactical' => [
            'name' => '対策型',
            'build_name' => '遅滞布陣',
            'purpose' => '予告大技・PvP',
            'description' => '大技を1行動遅らせ、燃費と発動補助で自分の展開を先に通します。',
            'tags' => ['大技対策', '1行動遅延'],
            'variants' => [
                'advanced' => $variant(['48:1', '48:5', '27:1', '12:5', '48:9'], ['48:5' => $counterplay]),
                'super' => $variant(['59:1', '48:5', '48:1', '59:5', '48:9'], ['48:5' => $counterplay]),
                'crown' => $variant(['12:1', '69:1', '48:5', '69:5', '48:9'], ['48:5' => $counterplay]),
            ],
        ],
    ],
];
