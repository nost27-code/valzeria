<?php

namespace App\Support;

class NormalDropMaterialConsolidator
{
    public const TYPE_COMMON = 'common_drop';
    public const TYPE_REGIONAL = 'regional_drop';
    public const TYPE_LEGACY = 'legacy_normal_drop';

    public static function definitions(): array
    {
        return [
            'MAT_COMMON_SLIME_MUCUS' => ['name' => 'スライムの粘液', 'type' => self::TYPE_COMMON, 'category_id' => 'slime', 'price' => 10],
            'MAT_COMMON_BEAST_FANG' => ['name' => '獣牙', 'type' => self::TYPE_COMMON, 'category_id' => 'beast_fang', 'price' => 20],
            'MAT_COMMON_GOBLIN_FANG' => ['name' => '小鬼の牙', 'type' => self::TYPE_COMMON, 'category_id' => 'goblin', 'price' => 20],
            'MAT_COMMON_BEAST_FUR' => ['name' => '獣の毛皮', 'type' => self::TYPE_COMMON, 'category_id' => 'beast_fur', 'price' => 20],
            'MAT_COMMON_WING_MEMBRANE' => ['name' => '薄い翼膜', 'type' => self::TYPE_COMMON, 'category_id' => 'wing', 'price' => 20],
            'MAT_COMMON_MONSTER_SHELL' => ['name' => '魔物の外殻', 'type' => self::TYPE_COMMON, 'category_id' => 'shell', 'price' => 30],
            'MAT_COMMON_OLD_BONE' => ['name' => '古びた骨片', 'type' => self::TYPE_COMMON, 'category_id' => 'bone', 'price' => 20],
            'MAT_COMMON_OLD_BADGE' => ['name' => '古びた徽章', 'type' => self::TYPE_COMMON, 'category_id' => 'badge', 'price' => 30],
            'MAT_COMMON_MONSTER_CORE' => ['name' => '魔物の魔核', 'type' => self::TYPE_COMMON, 'category_id' => 'core', 'price' => 40],
            'MAT_COMMON_MAGIC_ORE' => ['name' => '魔鉱片', 'type' => self::TYPE_COMMON, 'category_id' => 'ore', 'price' => 30],
            'MAT_COMMON_FAIRY_DUST' => ['name' => '妖精粉', 'type' => self::TYPE_COMMON, 'category_id' => 'fairy', 'price' => 30],
            'MAT_COMMON_HOLY_FRAGMENT' => ['name' => '聖片', 'type' => self::TYPE_COMMON, 'category_id' => 'holy', 'price' => 40],
            'MAT_COMMON_DARK_CRYSTAL' => ['name' => '黒結晶', 'type' => self::TYPE_COMMON, 'category_id' => 'dark', 'price' => 40],
            'MAT_COMMON_MONSTER_FRAGMENT' => ['name' => '魔物の欠片', 'type' => self::TYPE_COMMON, 'category_id' => 'monster_fragment', 'price' => 10],
            'MAT_COMMON_NATURAL_FRAGMENT' => ['name' => '自然片', 'type' => self::TYPE_COMMON, 'category_id' => 'natural', 'price' => 20],
            'MAT_COMMON_FIRE_SEED' => ['name' => '火種', 'type' => self::TYPE_COMMON, 'category_id' => 'fire', 'price' => 30],
            'MAT_COMMON_DRAGON_SCALE' => ['name' => '竜鱗', 'type' => self::TYPE_COMMON, 'category_id' => 'dragon', 'price' => 50],
            'MAT_COMMON_THUNDER_STONE' => ['name' => '雷石', 'type' => self::TYPE_COMMON, 'category_id' => 'thunder', 'price' => 40],
            'MAT_COMMON_FEATHER' => ['name' => '羽根', 'type' => self::TYPE_COMMON, 'category_id' => 'feather', 'price' => 20],
            'MAT_COMMON_ROTTEN_CLOTH' => ['name' => '腐布', 'type' => self::TYPE_COMMON, 'category_id' => 'cloth', 'price' => 20],
            'MAT_COMMON_MAGIC_CRYSTAL' => ['name' => '魔力水晶', 'type' => self::TYPE_COMMON, 'category_id' => 'crystal', 'price' => 40],
            'MAT_REGION_ARKREA_RAW' => ['name' => 'アークレアの粗素材', 'type' => self::TYPE_REGIONAL, 'category_id' => 'arkrea', 'price' => 30],
            'MAT_REGION_TIDAL_PIECE' => ['name' => '潮騒の素材片', 'type' => self::TYPE_REGIONAL, 'category_id' => 'tidal', 'price' => 30],
            'MAT_REGION_WORLD_TREE_LEAF' => ['name' => '世界樹の葉片', 'type' => self::TYPE_REGIONAL, 'category_id' => 'world_tree', 'price' => 30],
            'MAT_REGION_BLACK_IRON_PART' => ['name' => '黒鉄の部材', 'type' => self::TYPE_REGIONAL, 'category_id' => 'black_iron', 'price' => 40],
            'MAT_REGION_ICE_CRYSTAL' => ['name' => '氷晶片', 'type' => self::TYPE_REGIONAL, 'category_id' => 'ice', 'price' => 40],
            'MAT_REGION_ANCIENT_SAND' => ['name' => '古代砂晶', 'type' => self::TYPE_REGIONAL, 'category_id' => 'ancient_sand', 'price' => 40],
            'MAT_REGION_MAGIC_CRYSTAL' => ['name' => '魔導結晶', 'type' => self::TYPE_REGIONAL, 'category_id' => 'magic_city', 'price' => 50],
            'MAT_REGION_ABYSS_FRAGMENT' => ['name' => '深淵の欠片', 'type' => self::TYPE_REGIONAL, 'category_id' => 'abyss', 'price' => 60],
            'MAT_REGION_HEAVEN_FEATHER' => ['name' => '天界の羽根', 'type' => self::TYPE_REGIONAL, 'category_id' => 'heaven', 'price' => 60],
        ];
    }

    public static function isLegacyNormalCode(string $materialCode): bool
    {
        return preg_match('/^MAT\d{4}$/', $materialCode) === 1;
    }

    public static function targetCodeFor(string $materialName, ?int $cityId = null): string
    {
        $name = str_replace(['上質な', '希少な'], '', $materialName);

        return match (true) {
            str_contains($name, 'スライム') || str_contains($name, '粘液') => 'MAT_COMMON_SLIME_MUCUS',
            str_contains($name, '竜鱗') || str_contains($name, '鱗') => 'MAT_COMMON_DRAGON_SCALE',
            str_contains($name, '牙') || str_contains($name, '爪') || str_contains($name, 'ゴブリン') || str_contains($name, '小鬼') => 'MAT_COMMON_BEAST_FANG',
            str_contains($name, '毛皮') => 'MAT_COMMON_BEAST_FUR',
            str_contains($name, '翼膜') => 'MAT_COMMON_WING_MEMBRANE',
            str_contains($name, '外殻') || str_contains($name, '甲殻') => 'MAT_COMMON_MONSTER_SHELL',
            str_contains($name, '古骨') || str_contains($name, '骨') => 'MAT_COMMON_OLD_BONE',
            str_contains($name, '徽章') => 'MAT_COMMON_OLD_BADGE',
            str_contains($name, '魔核') || str_contains($name, '核') => 'MAT_COMMON_MONSTER_CORE',
            str_contains($name, '鉱') || str_contains($name, '鉄') => 'MAT_COMMON_MAGIC_ORE',
            str_contains($name, '妖精粉') || str_contains($name, '粉') => 'MAT_COMMON_FAIRY_DUST',
            str_contains($name, '聖片') || str_contains($name, '聖') => 'MAT_COMMON_HOLY_FRAGMENT',
            str_contains($name, '黒結晶') || str_contains($name, '闇') => 'MAT_COMMON_DARK_CRYSTAL',
            str_contains($name, '自然片') || str_contains($name, '葉') || str_contains($name, '樹') => 'MAT_COMMON_NATURAL_FRAGMENT',
            str_contains($name, '火種') || str_contains($name, '炎') => 'MAT_COMMON_FIRE_SEED',
            str_contains($name, '雷石') || str_contains($name, '雷') => 'MAT_COMMON_THUNDER_STONE',
            str_contains($name, '羽根') || str_contains($name, '羽') => 'MAT_COMMON_FEATHER',
            str_contains($name, '腐布') || str_contains($name, '布') => 'MAT_COMMON_ROTTEN_CLOTH',
            str_contains($name, '水晶') || str_contains($name, '結晶') => 'MAT_COMMON_MAGIC_CRYSTAL',
            default => self::regionCodeForCity($cityId),
        };
    }

    public static function regionCodeForCity(?int $cityId): string
    {
        return match ($cityId) {
            1 => 'MAT_REGION_ARKREA_RAW',
            2 => 'MAT_REGION_TIDAL_PIECE',
            3 => 'MAT_REGION_WORLD_TREE_LEAF',
            4 => 'MAT_REGION_BLACK_IRON_PART',
            5 => 'MAT_REGION_ICE_CRYSTAL',
            6 => 'MAT_REGION_ANCIENT_SAND',
            7 => 'MAT_REGION_MAGIC_CRYSTAL',
            8 => 'MAT_REGION_ABYSS_FRAGMENT',
            9 => 'MAT_REGION_HEAVEN_FEATHER',
            default => 'MAT_COMMON_MONSTER_FRAGMENT',
        };
    }

    public static function payload(string $materialCode): array
    {
        $definition = self::definitions()[$materialCode] ?? self::definitions()['MAT_COMMON_MONSTER_FRAGMENT'];

        return [
            'name' => $definition['name'],
            'category' => $definition['type'] === self::TYPE_REGIONAL ? '地域素材' : '共通素材',
            'rarity' => $definition['type'] === self::TYPE_REGIONAL ? 'R' : 'N',
            'element' => null,
            'main_use' => '武具合成・市場取引・売却',
            'npc_sale_price' => $definition['price'],
            'is_tradable' => true,
            'material_type' => $definition['type'],
            'category_id' => $definition['category_id'],
            'rank_tier' => $definition['type'] === self::TYPE_REGIONAL ? 2 : 1,
            'is_consumable' => true,
            'obtain_method' => '通常敵ドロップ素材を整理統合した合成用素材。',
        ];
    }
}
