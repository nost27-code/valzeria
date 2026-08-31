<?php

namespace App\Support;

final class MonsterMarkTitleCatalog
{
    public const FIRST_TITLE_ID = 132;

    public const LAST_TITLE_ID = 271;

    /** @var array<int, string> */
    private const AREA_NAMES = [
        1 => 'はじまりの草原',
        2 => '小鬼の森',
        3 => '古びた洞窟',
        4 => '狼の丘',
        5 => '忘れられた墓地',
        6 => '妖精の泉',
        7 => '見習い訓練場',
        8 => '潮風の海岸',
        9 => '海蝕洞窟',
        10 => '難破船の残骸',
        11 => '人魚の入り江',
        12 => '海賊の隠れ家',
        13 => '珊瑚迷宮',
        14 => '深海神殿',
        15 => '若葉の森',
        16 => '妖精の森',
        17 => '世界樹の根',
        18 => '世界樹中層',
        19 => '世界樹上層',
        20 => '精霊神殿',
        21 => '月光庭園',
        22 => '鉄鉱山',
        23 => '廃坑',
        24 => '溶鉱炉跡',
        25 => '蒸気工場',
        26 => '機械兵工場',
        27 => '地底発電所',
        28 => '古代兵器庫',
        29 => '氷雪平原',
        30 => '吹雪の峡谷',
        31 => '氷結洞窟',
        32 => '白銀の森',
        33 => '凍てつく神殿',
        34 => '氷竜の巣',
        35 => '極寒山脈',
        36 => '砂海',
        37 => '流砂地帯',
        38 => '古代遺跡',
        39 => '王家の墓',
        40 => '砂嵐の神殿',
        41 => '地下水路',
        42 => '太陽神殿',
        43 => '魔導図書館',
        44 => '禁書庫',
        45 => '魔法研究所',
        46 => '浮遊庭園',
        47 => '星見の塔',
        48 => '異界観測所',
        49 => '次元回廊',
        50 => '死者の荒野',
        51 => '呪われた城',
        52 => '冥界門',
        53 => '悪魔神殿',
        54 => '魔王軍要塞',
        55 => '瘴気の谷',
        56 => '奈落への階段',
        57 => '雲海平原',
        58 => '天空回廊',
        59 => '雷鳴神殿',
        60 => '浮遊遺跡',
        61 => '天使の庭園',
        62 => '星辰の塔',
        63 => '神々の祭壇',
        64 => '魔王領外郭',
        65 => '絶望の回廊',
        66 => '魔神の間',
        67 => '深淵の牢獄',
        68 => '黒き玉座',
        69 => '魔王城中枢',
        70 => '終焉の祭壇',
    ];

    /**
     * @return array<int, array<string, int|string|bool>>
     */
    public static function definitions(): array
    {
        $definitions = [];

        foreach (self::AREA_NAMES as $areaId => $areaName) {
            $collectionTitleId = self::FIRST_TITLE_ID + (($areaId - 1) * 2);
            $fullTitleId = $collectionTitleId + 1;

            $definitions[$collectionTitleId] = [
                'category' => 'monster_mark',
                'rarity' => 'rare',
                'name' => $areaName.'の印収集家',
                'description' => $areaName.'のすべての印を1個以上集める',
                'hint' => $areaName.'に棲む魔物の印を、すべて集めよう。',
                'unlock_type' => 'monster_mark_area_complete',
                'target_type' => 'area',
                'target_id' => (string) $areaId,
                'source_master' => '印図鑑/エリア別収集',
                'display_order' => 2000 + (($areaId - 1) * 2),
                'is_hidden' => true,
            ];
            $definitions[$fullTitleId] = [
                'category' => 'monster_mark',
                'rarity' => 'legendary',
                'name' => $areaName.'の印を極めし者',
                'description' => $areaName.'のすべての印を15個以上集める',
                'hint' => $areaName.'に棲む魔物の印を、すべて15個まで集めよう。',
                'unlock_type' => 'monster_mark_area_full_complete',
                'target_type' => 'area',
                'target_id' => (string) $areaId,
                'source_master' => '印図鑑/エリア別収集',
                'display_order' => 2001 + (($areaId - 1) * 2),
                'is_hidden' => true,
            ];
        }

        return $definitions;
    }

    /** @return list<int> */
    public static function titleIds(): array
    {
        return array_keys(self::definitions());
    }
}
