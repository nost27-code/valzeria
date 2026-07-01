<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Material extends Model
{
    protected $guarded = [];

    private const ICON_BY_CODE = [
        'MAT_ENHANCE_FRAGMENT' => 'images/icon/icon_094.webp',
        '5007' => 'images/icon/icon_097.webp',
        'ACC0007' => 'images/icon/icon_100.webp',
        'MAT_ENHANCE_STONE' => 'images/icon/icon_095.webp',
        '5008' => 'images/icon/icon_098.webp',
        'ACC0008' => 'images/icon/icon_101.webp',
        'MAT_ENHANCE_HIGH_STONE' => 'images/icon/icon_095.webp',
        '5009' => 'images/icon/icon_098.webp',
        'ACC0009' => 'images/icon/icon_102.webp',
        'MAT_REFINING_CORE_LOW' => 'images/icon/icon_103.webp',
        'MAT_REFINING_CORE' => 'images/icon/icon_104.webp',
        'MAT_REFINING_CORE_LOW_A' => 'images/icon/icon_105.webp',
        'MAT_REFINING_CORE_LOW_B' => 'images/icon/icon_106.webp',
        'MAT_REFINING_CORE_PART_A' => 'images/icon/icon_107.webp',
        'MAT_REFINING_CORE_PART_B' => 'images/icon/icon_108.webp',
        'MAT_REFINING_CORE_PART_C' => 'images/icon/icon_109.webp',
        'MAT_COMMON_MAGIC_ORE' => 'images/icon/icon_110.webp',
        'MAT_COMMON_MONSTER_CORE' => 'images/icon/icon_111.webp',
        '5025' => 'images/icon/icon_112.webp',
        '5026' => 'images/icon/icon_113.webp',
        'WEV0033' => 'images/icon/icon_114.webp',
        '5027' => 'images/icon/icon_115.webp',
        '5028' => 'images/icon/icon_116.webp',
        'WEV0035' => 'images/icon/icon_117.webp',
        '5029' => 'images/icon/icon_118.webp',
        '5030' => 'images/icon/icon_119.webp',
        'WEV0037' => 'images/icon/icon_120.webp',
        '5031' => 'images/icon/icon_121.webp',
        '5032' => 'images/icon/icon_122.webp',
        'WEV0039' => 'images/icon/icon_123.webp',
        '5033' => 'images/icon/icon_124.webp',
        '5034' => 'images/icon/icon_125.webp',
        'WEV0041' => 'images/icon/icon_126.webp',
        '5035' => 'images/icon/icon_127.webp',
        '5036' => 'images/icon/icon_128.webp',
        'WEV0043' => 'images/icon/icon_129.webp',
        '5037' => 'images/icon/icon_130.webp',
        '5038' => 'images/icon/icon_131.webp',
        'WEV0045' => 'images/icon/icon_132.webp',
        '5039' => 'images/icon/icon_133.webp',
        '5040' => 'images/icon/icon_134.webp',
        'WEV0047' => 'images/icon/icon_135.webp',
        '5041' => 'images/icon/icon_136.webp',
        '5042' => 'images/icon/icon_137.webp',
        'WEV0049' => 'images/icon/icon_138.webp',
        '5043' => 'images/icon/icon_139.webp',
        '5044' => 'images/icon/icon_140.webp',
        'WEV0051' => 'images/icon/icon_141.webp',
        'MAT_BR_WPN_HOLY_COMPOSITE' => 'images/icon/icon_142.webp',
        'MAT_BR_WPN_DARK_COMPOSITE' => 'images/icon/icon_143.webp',
        'MAT_BR_WPN_GALE_COMPOSITE' => 'images/icon/icon_144.webp',
        'MAT_BR_ARM_HEAVY_COMPOSITE' => 'images/icon/icon_145.webp',
        'MAT_BR_ARM_ARCANE_COMPOSITE' => 'images/icon/icon_146.webp',
        'MAT_BR_ARM_LIGHT_COMPOSITE' => 'images/icon/icon_147.webp',
        'MAT_BR_ARM_TRAVELER_COMPOSITE' => 'images/icon/icon_148.webp',
        'MAT_BR_WPN_HOLY_SECRET_SHARD' => 'images/icon/icon_149.webp',
        'MAT_BR_WPN_HOLY_SECRET' => 'images/icon/icon_150.webp',
        'MAT_BR_WPN_HOLY_CREST' => 'images/icon/icon_151.webp',
        'MAT_BR_WPN_DARK_SECRET_SHARD' => 'images/icon/icon_152.webp',
        'MAT_BR_WPN_DARK_SECRET' => 'images/icon/icon_153.webp',
        'MAT_BR_WPN_DARK_CREST' => 'images/icon/icon_154.webp',
        'MAT_BR_WPN_GALE_SECRET_SHARD' => 'images/icon/icon_155.webp',
        'MAT_BR_WPN_GALE_SECRET' => 'images/icon/icon_156.webp',
        'MAT_BR_WPN_GALE_CREST' => 'images/icon/icon_157.webp',
        'MAT_BR_ARM_HEAVY_SECRET_SHARD' => 'images/icon/icon_158.webp',
        'MAT_BR_ARM_HEAVY_SECRET' => 'images/icon/icon_159.webp',
        'MAT_BR_ARM_HEAVY_CREST' => 'images/icon/icon_160.webp',
        'MAT_BR_ARM_ARCANE_SECRET_SHARD' => 'images/icon/icon_161.webp',
        'MAT_BR_ARM_ARCANE_SECRET' => 'images/icon/icon_162.webp',
        'MAT_BR_ARM_ARCANE_CREST' => 'images/icon/icon_163.webp',
        'MAT_BR_ARM_LIGHT_SECRET_SHARD' => 'images/icon/icon_164.webp',
        'MAT_BR_ARM_LIGHT_SECRET' => 'images/icon/icon_165.webp',
        'MAT_BR_ARM_LIGHT_CREST' => 'images/icon/icon_166.webp',
        'MAT_BR_ARM_TRAVELER_SECRET_SHARD' => 'images/icon/icon_167.webp',
        'MAT_BR_ARM_TRAVELER_SECRET' => 'images/icon/icon_168.webp',
        'MAT_BR_ARM_TRAVELER_CREST' => 'images/icon/icon_169.webp',
        'ACC0003' => 'images/icon/icon_170.webp',
        'ACC0011' => 'images/icon/icon_171.webp',
        'ACC0014' => 'images/icon/icon_172.webp',
        'ACC0017' => 'images/icon/icon_173.webp',
        'ACC0020' => 'images/icon/icon_174.webp',
        'ACC0023' => 'images/icon/icon_175.webp',
        'ACC0026' => 'images/icon/icon_176.webp',
        'ACC0029' => 'images/icon/icon_177.webp',
        'ACC0032' => 'images/icon/icon_178.webp',
        'ACC0035' => 'images/icon/icon_179.webp',
        'ACC0038' => 'images/icon/icon_180.webp',
        'MAT_BR_WPN_HOLY_PATH' => 'images/icon/icon_181.webp',
        'MAT_BR_WPN_DARK_PATH' => 'images/icon/icon_182.webp',
        'MAT_BR_WPN_GALE_PATH' => 'images/icon/icon_183.webp',
        'MAT_BR_ARM_HEAVY_ARCANE_PATH' => 'images/icon/icon_184.webp',
        'MAT_BR_ARM_LIGHT_TRAVELER_PATH' => 'images/icon/icon_185.webp',
        'MAT_REGION_ARKREA_RAW' => 'images/icon/icon_186.webp',
        'MAT0001' => 'images/icon/icon_187.webp',
        'MAT_COMMON_SLIME_MUCUS' => 'images/icon/icon_187.webp',
        'MAT_COMMON_GOBLIN_FANG' => 'images/icon/icon_188.webp',
        'MAT_COMMON_BEAST_FANG' => 'images/icon/icon_189.webp',
        'MAT_COMMON_OLD_BADGE' => 'images/icon/icon_190.webp',
        'MAT_COMMON_MONSTER_FRAGMENT' => 'images/icon/icon_191.webp',
        'MAT_COMMON_MONSTER_SHELL' => 'images/icon/icon_192.webp',
        'MAT_COMMON_ROTTEN_CLOTH' => 'images/icon/icon_193.webp',
        'MAT_COMMON_BEAST_FUR' => 'images/icon/icon_194.webp',
        'MAT_COMMON_WING_MEMBRANE' => 'images/icon/icon_195.webp',
        'MAT_COMMON_HOLY_FRAGMENT' => 'images/icon/icon_196.webp',
        'MAT_REGION_TIDAL_PIECE' => 'images/icon/icon_197.webp',
        'MAT_REGION_WORLD_TREE_LEAF' => 'images/icon/icon_198.webp',
        'MAT_REGION_ICE_CRYSTAL' => 'images/icon/icon_199.webp',
        'MAT_COMMON_DARK_CRYSTAL' => 'images/icon/icon_200.webp',
        'MAT_REGION_BLACK_IRON_PART' => 'images/icon/icon_201.webp',
        'MAT0032' => 'images/icon/icon_202.webp',
        'MAT_COMMON_FAIRY_DUST' => 'images/icon/icon_202.webp',
        'MAT_REGION_HEAVEN_FEATHER' => 'images/icon/icon_203.webp',
        'CITY_07_MATERIAL' => 'images/icon/icon_204.webp',
        'WEV0029' => 'images/icon/icon_204.webp',
        'MAT_REGION_MAGIC_CRYSTAL' => 'images/icon/icon_204.webp',
        'MAT_REGION_ANCIENT_SAND' => 'images/icon/icon_205.webp',
        'MAT_COMMON_OLD_BONE' => 'images/icon/icon_206.webp',
        'CITY_09_MATERIAL' => 'images/icon/icon_207.webp',
        'WEV0031' => 'images/icon/icon_207.webp',
        'MAT_STARDUST_FORGE' => 'images/icon/icon_208.webp',
        'WEV0005' => 'images/icon/icon_208.webp',
        'CITY_05_MATERIAL' => 'images/icon/icon_209.webp',
        'WEV0027' => 'images/icon/icon_209.webp',
        'MAT_REGION_ABYSS_FRAGMENT' => 'images/icon/icon_210.webp',
        'CITY_02_MATERIAL' => 'images/icon/icon_211.webp',
        'WEV0024' => 'images/icon/icon_211.webp',
        'MAT_COMMON_FIRE_SEED' => 'images/icon/icon_212.webp',
        'CITY_01_MATERIAL' => 'images/icon/icon_213.webp',
        'WEV0023' => 'images/icon/icon_213.webp',
        'CITY_06_MATERIAL' => 'images/icon/icon_214.webp',
        'WEV0028' => 'images/icon/icon_214.webp',
        'MAT_COMMON_DRAGON_SCALE' => 'images/icon/icon_215.webp',
        'CITY_03_MATERIAL' => 'images/icon/icon_216.webp',
        'WEV0025' => 'images/icon/icon_216.webp',
        'MAT_COMMON_FEATHER' => 'images/icon/icon_217.webp',
        'MAT_COMMON_NATURAL_FRAGMENT' => 'images/icon/icon_218.webp',
        'MAT_COMMON_THUNDER_STONE' => 'images/icon/icon_219.webp',
        'MAT_COMMON_MAGIC_CRYSTAL' => 'images/icon/icon_220.webp',
        'CITY_10_MATERIAL' => 'images/icon/icon_221.webp',
        'WEV0032' => 'images/icon/icon_221.webp',
        'CITY_04_MATERIAL' => 'images/icon/icon_222.webp',
        'WEV0026' => 'images/icon/icon_222.webp',
        'MAT_BR_ACC_PRIMORDIAL_ORNAMENT_CRYSTAL' => 'images/icon/icon_239.webp',
    ];

    private const ICON_BY_NAME = [
        '強化石の欠片' => 'images/icon/icon_094.webp',
        '守護石の欠片' => 'images/icon/icon_097.webp',
        '調律石の欠片' => 'images/icon/icon_100.webp',
        '強化石' => 'images/icon/icon_095.webp',
        '守護石' => 'images/icon/icon_098.webp',
        '調律石' => 'images/icon/icon_101.webp',
        '高純度強化石' => 'images/icon/icon_095.webp',
        '高純度守護石' => 'images/icon/icon_098.webp',
        '高純度調律石' => 'images/icon/icon_102.webp',
        '粗精錬核' => 'images/icon/icon_103.webp',
        '精錬核' => 'images/icon/icon_104.webp',
        '織成核殻' => 'images/icon/icon_105.webp',
        '晶糸核芯' => 'images/icon/icon_106.webp',
        '覇王黒晶' => 'images/icon/icon_107.webp',
        '蒼炉魔晶' => 'images/icon/icon_108.webp',
        '星樹氷晶' => 'images/icon/icon_109.webp',
        '魔鉱片' => 'images/icon/icon_110.webp',
        '魔物の魔核' => 'images/icon/icon_111.webp',
        '王都の織布' => 'images/icon/icon_112.webp',
        '王都の守護布' => 'images/icon/icon_113.webp',
        '王紋鋼' => 'images/icon/icon_114.webp',
        '潮風の布片' => 'images/icon/icon_115.webp',
        '海守りの織布' => 'images/icon/icon_116.webp',
        '海鳴りの蒼鉱' => 'images/icon/icon_117.webp',
        '精霊樹の繊維' => 'images/icon/icon_118.webp',
        '精霊王の絹糸' => 'images/icon/icon_119.webp',
        '精霊樹の琥珀' => 'images/icon/icon_120.webp',
        '黒鉄の装甲片' => 'images/icon/icon_121.webp',
        '炉心の耐熱布' => 'images/icon/icon_122.webp',
        '炉心鋼' => 'images/icon/icon_123.webp',
        '氷晶の織糸' => 'images/icon/icon_124.webp',
        '氷帝の守護布' => 'images/icon/icon_125.webp',
        '氷帝晶' => 'images/icon/icon_126.webp',
        '砂金繊維' => 'images/icon/icon_127.webp',
        '砂王の宝布' => 'images/icon/icon_128.webp',
        '砂王金晶' => 'images/icon/icon_129.webp',
        '魔導繊維' => 'images/icon/icon_130.webp',
        '大魔導の星布' => 'images/icon/icon_131.webp',
        'ルミナス魔晶' => 'images/icon/icon_132.webp',
        '瘴気の革片' => 'images/icon/icon_133.webp',
        '深魔の黒布' => 'images/icon/icon_134.webp',
        '深魔骨核' => 'images/icon/icon_135.webp',
        '天空の羽布' => 'images/icon/icon_136.webp',
        '天空竜の織布' => 'images/icon/icon_137.webp',
        'セレスティア星晶' => 'images/icon/icon_138.webp',
        '魔王城の黒布' => 'images/icon/icon_139.webp',
        '魔王の黒装片' => 'images/icon/icon_140.webp',
        'ヴァルゼリア黒核' => 'images/icon/icon_141.webp',
        '聖天織晶' => 'images/icon/icon_142.webp',
        '冥黒織晶' => 'images/icon/icon_143.webp',
        '翠嵐織晶' => 'images/icon/icon_144.webp',
        '鋼氷護晶' => 'images/icon/icon_145.webp',
        '星導魔晶' => 'images/icon/icon_146.webp',
        '風精織晶' => 'images/icon/icon_147.webp',
        '砂海旅晶' => 'images/icon/icon_148.webp',
        '聖剣の秘境晶の欠片' => 'images/icon/icon_149.webp',
        '聖剣の秘境晶片' => 'images/icon/icon_149.webp',
        '聖剣の秘境晶' => 'images/icon/icon_150.webp',
        '聖剣の極印' => 'images/icon/icon_151.webp',
        '魔剣の秘境晶の欠片' => 'images/icon/icon_152.webp',
        '魔剣の秘境晶片' => 'images/icon/icon_152.webp',
        '魔剣の秘境晶' => 'images/icon/icon_153.webp',
        '魔剣の極印' => 'images/icon/icon_154.webp',
        '疾風の秘境晶の欠片' => 'images/icon/icon_155.webp',
        '疾風の秘境晶片' => 'images/icon/icon_155.webp',
        '疾風の秘境晶' => 'images/icon/icon_156.webp',
        '疾風の極印' => 'images/icon/icon_157.webp',
        '重装の秘境晶の欠片' => 'images/icon/icon_158.webp',
        '重装の秘境晶片' => 'images/icon/icon_158.webp',
        '重装の秘境晶' => 'images/icon/icon_159.webp',
        '重装の極印' => 'images/icon/icon_160.webp',
        '魔装の秘境晶の欠片' => 'images/icon/icon_161.webp',
        '魔装の秘境晶片' => 'images/icon/icon_161.webp',
        '魔装の秘境晶' => 'images/icon/icon_162.webp',
        '魔装の極印' => 'images/icon/icon_163.webp',
        '軽装の秘境晶の欠片' => 'images/icon/icon_164.webp',
        '軽装の秘境晶片' => 'images/icon/icon_164.webp',
        '軽装の秘境晶' => 'images/icon/icon_165.webp',
        '軽装の極印' => 'images/icon/icon_166.webp',
        '旅装の秘境晶の欠片' => 'images/icon/icon_167.webp',
        '旅装の秘境晶片' => 'images/icon/icon_167.webp',
        '旅装の秘境晶' => 'images/icon/icon_168.webp',
        '旅装の極印' => 'images/icon/icon_169.webp',
        '装飾の核' => 'images/icon/icon_170.webp',
        '腕力の結晶' => 'images/icon/icon_171.webp',
        '守護の結晶' => 'images/icon/icon_172.webp',
        '魔力の結晶' => 'images/icon/icon_173.webp',
        '祈祷の結晶' => 'images/icon/icon_174.webp',
        '疾風の結晶' => 'images/icon/icon_175.webp',
        '幸運の結晶' => 'images/icon/icon_176.webp',
        '生命の結晶' => 'images/icon/icon_177.webp',
        '精神の結晶' => 'images/icon/icon_178.webp',
        '均衡の結晶' => 'images/icon/icon_179.webp',
        '冒険の結晶' => 'images/icon/icon_180.webp',
        '聖剣の導石' => 'images/icon/icon_181.webp',
        '魔剣の導石' => 'images/icon/icon_182.webp',
        '迅刃の導石' => 'images/icon/icon_183.webp',
        '疾風の導石' => 'images/icon/icon_183.webp',
        '重魔装の導石' => 'images/icon/icon_184.webp',
        '軽旅装の導石' => 'images/icon/icon_185.webp',
        'アークレアの粗素材' => 'images/icon/icon_186.webp',
        'スライムの粘液' => 'images/icon/icon_187.webp',
        '小鬼の牙' => 'images/icon/icon_188.webp',
        '獣牙' => 'images/icon/icon_189.webp',
        '古びた徽章' => 'images/icon/icon_190.webp',
        '魔物の欠片' => 'images/icon/icon_191.webp',
        '魔物の外殻' => 'images/icon/icon_192.webp',
        '腐布' => 'images/icon/icon_193.webp',
        '獣の毛皮' => 'images/icon/icon_194.webp',
        '薄い翼膜' => 'images/icon/icon_195.webp',
        '聖片' => 'images/icon/icon_196.webp',
        '潮騒の素材片' => 'images/icon/icon_197.webp',
        '世界樹の葉片' => 'images/icon/icon_198.webp',
        '氷晶片' => 'images/icon/icon_199.webp',
        '黒結晶' => 'images/icon/icon_200.webp',
        '黒鉄の部材' => 'images/icon/icon_201.webp',
        '妖精粉' => 'images/icon/icon_202.webp',
        '天界の羽根' => 'images/icon/icon_203.webp',
        '魔導結晶' => 'images/icon/icon_204.webp',
        '古代砂晶' => 'images/icon/icon_205.webp',
        '古びた骨片' => 'images/icon/icon_206.webp',
        '天空石' => 'images/icon/icon_207.webp',
        '星屑の鍛材' => 'images/icon/icon_208.webp',
        '氷晶石' => 'images/icon/icon_209.webp',
        '深淵の欠片' => 'images/icon/icon_210.webp',
        '潮風の貝殻' => 'images/icon/icon_211.webp',
        '火種' => 'images/icon/icon_212.webp',
        '王都の鉄片' => 'images/icon/icon_213.webp',
        '砂金石' => 'images/icon/icon_214.webp',
        '竜鱗' => 'images/icon/icon_215.webp',
        '精霊樹の葉' => 'images/icon/icon_216.webp',
        '羽根' => 'images/icon/icon_217.webp',
        '自然片' => 'images/icon/icon_218.webp',
        '雷石' => 'images/icon/icon_219.webp',
        '魔力水晶' => 'images/icon/icon_220.webp',
        '魔王城の黒晶' => 'images/icon/icon_221.webp',
        '黒鉄鉱' => 'images/icon/icon_222.webp',
        '原初秘境晶' => 'images/icon/icon_239.webp',
        '原初装飾晶' => 'images/icon/icon_239.webp',
    ];

    protected $casts = [
        'is_tradable' => 'boolean',
        'drop_rate' => 'decimal:2',
        'drop_first_clear_only' => 'boolean',
        'npc_sell_price' => 'integer',
        'market_min_price' => 'integer',
        'market_max_price' => 'integer',
        'source_area_id' => 'integer',
        'is_key_item' => 'boolean',
        'is_cash_item' => 'boolean',
        'usage_tags' => 'array',
        'acquisition_tags' => 'array',
        'display_order' => 'integer',
    ];

    public function scopeMarketable($query)
    {
        return $query
            ->where('is_tradable', true)
            ->where('trade_policy', 'marketable')
            ->where('is_key_item', false)
            ->where('is_cash_item', false);
    }

    public function marketMinPrice(): int
    {
        $explicit = (int) ($this->market_min_price ?? 0);
        if ($explicit > 0) {
            return $explicit;
        }

        return max(1, (int) ($this->npc_sell_price ?? $this->npc_sale_price ?? 0));
    }

    public function getMarketMinPrice(): int
    {
        return $this->marketMinPrice();
    }

    public function marketMaxPrice(): int
    {
        $explicit = (int) ($this->market_max_price ?? 0);
        if ($explicit > 0) {
            return max($explicit, $this->marketMinPrice());
        }

        $base = max(1, (int) ($this->npc_sell_price ?? $this->npc_sale_price ?? 1));

        return max($this->marketMinPrice(), $base * 5);
    }

    public function getMarketMaxPrice(): int
    {
        return $this->marketMaxPrice();
    }

    public function isMarketable(): bool
    {
        return (bool) ($this->is_tradable ?? false)
            && ($this->trade_policy ?? null) === 'marketable'
            && ! (bool) ($this->is_key_item ?? false)
            && ! (bool) ($this->is_cash_item ?? false);
    }

    public function isSellTreasure(): bool
    {
        return (string) ($this->material_type ?? '') === 'sell_treasure'
            || (string) ($this->category_id ?? '') === 'sell_treasure'
            || (string) ($this->category ?? '') === '換金品';
    }

    public function usageTags(): array
    {
        return $this->usage_tags ?? [];
    }

    public function acquisitionTags(): array
    {
        return $this->acquisition_tags ?? [];
    }

    public function displayName(): string
    {
        $name = (string) ($this->name ?? '素材');

        return trim(str_replace('[SR]', '', $name));
    }

    public function iconImagePath(): ?string
    {
        return self::iconImagePathFor($this->material_code ?? null, $this->name ?? null);
    }

    public static function iconImagePathFor(?string $materialCode, ?string $name): ?string
    {
        $materialCode = (string) $materialCode;
        $name = (string) $name;

        $path = self::ICON_BY_CODE[$materialCode]
            ?? self::ICON_BY_NAME[$name]
            ?? null;

        if ($path === null || $path === '') {
            return null;
        }

        return self::publicIconExists($path) ? $path : null;
    }

    private static function publicIconExists(string $path): bool
    {
        static $exists = [];

        $path = ltrim($path, '/');

        return $exists[$path] ??= is_file(public_path(str_replace('/', DIRECTORY_SEPARATOR, $path)));
    }

    public function marketUnavailableReason(): string
    {
        if ((bool) ($this->is_key_item ?? false)) {
            return '進行に関わる重要素材のため、市場では取引できません。';
        }

        if ((bool) ($this->is_cash_item ?? false)) {
            return '換金専用素材のため、市場では取引できません。';
        }

        if (! (bool) ($this->is_tradable ?? false) || ($this->trade_policy ?? null) !== 'marketable') {
            return 'この素材は市場で取引できません。';
        }

        return '';
    }
}
