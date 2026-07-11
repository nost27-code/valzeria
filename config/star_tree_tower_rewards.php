<?php

return [
    'tower_key' => 'star_tree_tower',
    'weapon_max_enhance' => 5,
    'card_background' => [
        'floor' => 50,
        'name' => 'エルフィア',
        'asset_type' => 'background',
        'asset_path' => 'images/profile/adventurer_card_bg03.webp',
        'source' => 'star_tree_tower_reward',
    ],
    'card_frame' => [
        'floor' => 100,
        'name' => 'エルフィア',
        'asset_type' => 'card_frame',
        'asset_path' => 'images/profile/adventurer_card_frame03.webp',
        'assets' => [
            [
                'asset_type' => 'card_frame',
                'asset_path' => 'images/profile/adventurer_card_frame03.webp',
            ],
            [
                'asset_type' => 'avatar_frame',
                'asset_path' => 'images/profile/adventurer_avatar_frame03.webp',
            ],
        ],
        'source' => 'star_tree_tower_reward',
    ],
    'weapon_categories' => [
        'sword' => '剣',
        'spear' => '槍',
        'axe' => '斧',
        'dagger' => '短剣',
        'bow' => '弓',
        'fist' => '拳甲',
        'gun' => '機銃',
        'staff' => '杖',
        'magic_device' => '魔導書',
    ],
    'weapon_rewards' => [
        50 => [
            'display_rank' => 'A+相当',
            'killer_damage_rate' => 0.03,
            'weapons' => [
                'sword' => ['name' => '星葉の剣', 'hp' => 0, 'sp' => 0, 'atk' => 70, 'def' => 14, 'mag' => 0, 'spr' => 0, 'spd' => 7, 'luk' => 0],
                'spear' => ['name' => '星葉の槍', 'hp' => 0, 'sp' => 0, 'atk' => 70, 'def' => 7, 'mag' => 0, 'spr' => 0, 'spd' => 14, 'luk' => 0],
                'axe' => ['name' => '星葉の戦斧', 'hp' => 0, 'sp' => 0, 'atk' => 98, 'def' => 0, 'mag' => 0, 'spr' => 0, 'spd' => -14, 'luk' => 0],
                'dagger' => ['name' => '星葉の短剣', 'hp' => 0, 'sp' => 0, 'atk' => 48, 'def' => 0, 'mag' => 0, 'spr' => 0, 'spd' => 28, 'luk' => 21],
                'bow' => ['name' => '星葉の弓', 'hp' => 0, 'sp' => 0, 'atk' => 55, 'def' => 0, 'mag' => 0, 'spr' => 0, 'spd' => 21, 'luk' => 21],
                'fist' => ['name' => '星葉の拳甲', 'hp' => 0, 'sp' => 0, 'atk' => 55, 'def' => 0, 'mag' => 0, 'spr' => 0, 'spd' => 28, 'luk' => 14],
                'gun' => ['name' => '星葉の機銃', 'hp' => 0, 'sp' => 0, 'atk' => 48, 'def' => 0, 'mag' => 48, 'spr' => 0, 'spd' => 0, 'luk' => 14],
                'staff' => ['name' => '星葉の杖', 'hp' => 0, 'sp' => 70, 'atk' => 0, 'def' => 0, 'mag' => 70, 'spr' => 21, 'spd' => 0, 'luk' => 0],
                'magic_device' => ['name' => '星葉の魔導書', 'hp' => 0, 'sp' => 105, 'atk' => 0, 'def' => 0, 'mag' => 84, 'spr' => 28, 'spd' => -7, 'luk' => 0],
            ],
        ],
        70 => [
            'display_rank' => 'S+相当',
            'killer_damage_rate' => 0.04,
            'weapons' => [
                'sword' => ['name' => '月枝の剣', 'hp' => 0, 'sp' => 0, 'atk' => 92, 'def' => 18, 'mag' => 0, 'spr' => 0, 'spd' => 9, 'luk' => 0],
                'spear' => ['name' => '月枝の槍', 'hp' => 0, 'sp' => 0, 'atk' => 92, 'def' => 9, 'mag' => 0, 'spr' => 0, 'spd' => 18, 'luk' => 0],
                'axe' => ['name' => '月枝の戦斧', 'hp' => 0, 'sp' => 0, 'atk' => 128, 'def' => 0, 'mag' => 0, 'spr' => 0, 'spd' => -18, 'luk' => 0],
                'dagger' => ['name' => '月枝の短剣', 'hp' => 0, 'sp' => 0, 'atk' => 64, 'def' => 0, 'mag' => 0, 'spr' => 0, 'spd' => 37, 'luk' => 28],
                'bow' => ['name' => '月枝の弓', 'hp' => 0, 'sp' => 0, 'atk' => 73, 'def' => 0, 'mag' => 0, 'spr' => 0, 'spd' => 28, 'luk' => 28],
                'fist' => ['name' => '月枝の拳甲', 'hp' => 0, 'sp' => 0, 'atk' => 73, 'def' => 0, 'mag' => 0, 'spr' => 0, 'spd' => 37, 'luk' => 18],
                'gun' => ['name' => '月枝の機銃', 'hp' => 0, 'sp' => 0, 'atk' => 64, 'def' => 0, 'mag' => 64, 'spr' => 0, 'spd' => 0, 'luk' => 18],
                'staff' => ['name' => '月露の杖', 'hp' => 0, 'sp' => 92, 'atk' => 0, 'def' => 0, 'mag' => 92, 'spr' => 28, 'spd' => 0, 'luk' => 0],
                'magic_device' => ['name' => '月枝の魔導書', 'hp' => 0, 'sp' => 138, 'atk' => 0, 'def' => 0, 'mag' => 110, 'spr' => 37, 'spd' => -9, 'luk' => 0],
            ],
        ],
        90 => [
            'display_rank' => 'SS+相当',
            'killer_damage_rate' => 0.05,
            'weapons' => [
                'sword' => ['name' => '星樹の剣', 'hp' => 0, 'sp' => 0, 'atk' => 121, 'def' => 24, 'mag' => 0, 'spr' => 0, 'spd' => 12, 'luk' => 0],
                'spear' => ['name' => '星樹の聖槍', 'hp' => 0, 'sp' => 0, 'atk' => 121, 'def' => 12, 'mag' => 0, 'spr' => 0, 'spd' => 24, 'luk' => 0],
                'axe' => ['name' => '星樹の大斧', 'hp' => 0, 'sp' => 0, 'atk' => 169, 'def' => 0, 'mag' => 0, 'spr' => 0, 'spd' => -24, 'luk' => 0],
                'dagger' => ['name' => '星樹の影刃', 'hp' => 0, 'sp' => 0, 'atk' => 85, 'def' => 0, 'mag' => 0, 'spr' => 0, 'spd' => 48, 'luk' => 36],
                'bow' => ['name' => '星樹の神弓', 'hp' => 0, 'sp' => 0, 'atk' => 97, 'def' => 0, 'mag' => 0, 'spr' => 0, 'spd' => 36, 'luk' => 36],
                'fist' => ['name' => '星樹の拳甲', 'hp' => 0, 'sp' => 0, 'atk' => 97, 'def' => 0, 'mag' => 0, 'spr' => 0, 'spd' => 48, 'luk' => 24],
                'gun' => ['name' => '星樹の星銃', 'hp' => 0, 'sp' => 0, 'atk' => 85, 'def' => 0, 'mag' => 85, 'spr' => 0, 'spd' => 0, 'luk' => 24],
                'staff' => ['name' => '星樹の聖杖', 'hp' => 0, 'sp' => 121, 'atk' => 0, 'def' => 0, 'mag' => 121, 'spr' => 36, 'spd' => 0, 'luk' => 0],
                'magic_device' => ['name' => '星樹の星典', 'hp' => 0, 'sp' => 182, 'atk' => 0, 'def' => 0, 'mag' => 145, 'spr' => 48, 'spd' => -12, 'luk' => 0],
            ],
        ],
    ],
];
