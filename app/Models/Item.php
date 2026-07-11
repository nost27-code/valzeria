<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    private const WEAPON_ICON_BY_CATEGORY = [
        'sword' => 'images/icon/icon_224.webp',
        'bow' => 'images/icon/icon_225.webp',
        'dagger' => 'images/icon/icon_226.webp',
        'spear' => 'images/icon/icon_227.webp',
        'axe' => 'images/icon/icon_228.webp',
        'staff' => 'images/icon/icon_229.webp',
        'magic_device' => 'images/icon/icon_230.webp',
        'fist' => 'images/icon/icon_231.webp',
        'gun' => 'images/icon/icon_232.webp',
    ];

    private const ARMOR_ICON_BY_CATEGORY = [
        'heavy_armor' => 'images/icon/icon_233.webp',
        'heavy_material' => 'images/icon/icon_233.webp',
        '重装材' => 'images/icon/icon_233.webp',
        'light_armor' => 'images/icon/icon_234.webp',
        'light_material' => 'images/icon/icon_234.webp',
        'clothes' => 'images/icon/icon_234.webp',
        'cloak' => 'images/icon/icon_234.webp',
        '軽装材' => 'images/icon/icon_234.webp',
        'robe' => 'images/icon/icon_235.webp',
        'arcane_armor' => 'images/icon/icon_235.webp',
        'magic_cloth' => 'images/icon/icon_235.webp',
        '魔布材' => 'images/icon/icon_235.webp',
        'martial_garb' => 'images/icon/icon_236.webp',
        'martial_material' => 'images/icon/icon_236.webp',
        '闘具材' => 'images/icon/icon_236.webp',
        'holy_vestment' => 'images/icon/icon_237.webp',
        'holy_cloth' => 'images/icon/icon_237.webp',
        '聖布材' => 'images/icon/icon_237.webp',
    ];

    protected $fillable = [
        'name', 'type', 'description', 'rarity', 'display_rank', 'source_type', 'is_evolvable', 'price', 'sell_price',
        'hp_bonus', 'mp_bonus', 'str_bonus', 'def_bonus', 'agi_bonus', 'mag_bonus', 'spr_bonus', 'luk_bonus',
        'required_level', 'is_shop_item', 'is_active', 'sort_order', 'unlock_city_id',
        'sub_type', 'element',
        'weapon_category', 'weapon_hand_type', 'weapon_role',
        'external_item_id', 'weapon_family_id', 'weapon_family_name',
        'weapon_rank', 'weapon_rank_sort', 'weapon_rank_multiplier',
        'evolution_stage', 'next_item_external_id',
        'is_evolution_enabled', 'is_drop_enabled', 'affix_enabled', 'is_supply_enabled', 'max_enhance',
        'armor_category', 'armor_weight', 'armor_role',
        'armor_family_id', 'armor_family_name', 'armor_category_id', 'armor_category_name',
        'armor_rank', 'armor_rank_sort', 'armor_rank_multiplier',
        'evolution_group_id', 'next_armor_external_id',
        'accessory_family_id', 'accessory_family_name', 'accessory_category_id', 'accessory_category_name',
        'accessory_rank', 'accessory_rank_sort', 'accessory_rank_multiplier', 'next_accessory_external_id',
    ];

    protected $casts = [
        'is_shop_item' => 'boolean',
        'is_active' => 'boolean',
        'is_evolution_enabled' => 'boolean',
        'is_evolvable' => 'boolean',
        'is_drop_enabled' => 'boolean',
        'affix_enabled' => 'boolean',
        'is_supply_enabled' => 'boolean',
        'max_enhance' => 'integer',
    ];

    public function characterItems()
    {
        return $this->hasMany(CharacterItem::class);
    }

    public function enemyDrops()
    {
        return $this->hasMany(EnemyDrop::class);
    }

    public function iconImagePath(): ?string
    {
        return match ((string) $this->type) {
            'weapon' => self::WEAPON_ICON_BY_CATEGORY[(string) $this->weapon_category] ?? null,
            'armor' => self::armorIconImagePathFor($this),
            'accessory' => 'images/icon/icon_238.webp',
            default => null,
        };
    }

    private static function armorIconImagePathFor(self $item): ?string
    {
        foreach ([
            $item->armor_category_id,
            $item->armor_category_name,
            $item->armor_family_id,
            $item->armor_family_name,
            $item->armor_category,
        ] as $key) {
            $key = (string) $key;
            if ($key !== '' && isset(self::ARMOR_ICON_BY_CATEGORY[$key])) {
                return self::ARMOR_ICON_BY_CATEGORY[$key];
            }
        }

        return null;
    }
}
