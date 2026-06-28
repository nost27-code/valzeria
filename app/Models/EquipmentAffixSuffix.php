<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EquipmentAffixSuffix extends Model
{
    protected $fillable = [
        'item_type',
        'effect_type',
        'species_key',
        'name',
        'base_killer_rate',
        'base_effect_rate',
        'roll_weight',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'base_killer_rate' => 'float',
        'base_effect_rate' => 'float',
        'roll_weight' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];
}
