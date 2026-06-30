<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Material extends Model
{
    protected $guarded = [];

    private const ICON_BY_CODE = [
        'MAT_ENHANCE_FRAGMENT' => 'images/icon/icon_094.webp',
        '5007' => 'images/icon/icon_095.webp',
        'ACC0007' => 'images/icon/icon_096.webp',
        'MAT_ENHANCE_STONE' => 'images/icon/icon_097.webp',
        '5008' => 'images/icon/icon_098.webp',
        'ACC0008' => 'images/icon/icon_099.webp',
        'MAT_ENHANCE_HIGH_STONE' => 'images/icon/icon_100.webp',
        '5009' => 'images/icon/icon_101.webp',
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
    ];

    private const ICON_BY_NAME = [
        '強化石の欠片' => 'images/icon/icon_094.webp',
        '守護石の欠片' => 'images/icon/icon_095.webp',
        '調律石の欠片' => 'images/icon/icon_096.webp',
        '強化石' => 'images/icon/icon_097.webp',
        '守護石' => 'images/icon/icon_098.webp',
        '調律石' => 'images/icon/icon_099.webp',
        '高純度強化石' => 'images/icon/icon_100.webp',
        '高純度守護石' => 'images/icon/icon_101.webp',
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

        if (strtoupper((string) ($this->rarity ?? '')) !== 'SR') {
            return $name;
        }

        return str_ends_with($name, '[SR]') ? $name : "{$name} [SR]";
    }

    public function iconImagePath(): ?string
    {
        return self::iconImagePathFor($this->material_code ?? null, $this->name ?? null);
    }

    public static function iconImagePathFor(?string $materialCode, ?string $name): ?string
    {
        $materialCode = (string) $materialCode;
        $name = (string) $name;

        return self::ICON_BY_CODE[$materialCode]
            ?? self::ICON_BY_NAME[$name]
            ?? null;
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
