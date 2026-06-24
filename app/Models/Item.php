<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    protected $fillable = [
        'name', 'type', 'description', 'rarity', 'price', 'sell_price',
        'hp_bonus', 'mp_bonus', 'str_bonus', 'def_bonus', 'agi_bonus', 'mag_bonus', 'spr_bonus', 'luk_bonus',
        'required_level', 'is_shop_item', 'is_active', 'sort_order', 'unlock_city_id',
        'sub_type', 'element',
        'weapon_category', 'weapon_hand_type', 'weapon_role',
        'external_item_id', 'weapon_family_id', 'weapon_family_name',
        'weapon_rank', 'weapon_rank_sort', 'weapon_rank_multiplier',
        'evolution_stage', 'next_item_external_id',
        'is_evolution_enabled', 'is_drop_enabled', 'is_supply_enabled', 'max_enhance',
        'armor_category', 'armor_weight', 'armor_role',
        'armor_family_id', 'armor_family_name', 'armor_category_id', 'armor_category_name',
        'armor_rank', 'armor_rank_sort', 'armor_rank_multiplier',
        'evolution_group_id', 'next_armor_external_id',
        'accessory_family_id', 'accessory_family_name', 'accessory_category_id', 'accessory_category_name',
        'accessory_rank', 'accessory_rank_sort', 'accessory_rank_multiplier', 'next_accessory_external_id',
    ];

    public function characterItems()
    {
        return $this->hasMany(CharacterItem::class);
    }

    public function enemyDrops()
    {
        return $this->hasMany(EnemyDrop::class);
    }
}
