<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EquipmentAffixPrefix extends Model
{
    protected $fillable = [
        'affix_key',
        'name',
        'target_stat',
        'calculation_rate',
        'roll_weight',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'calculation_rate' => 'float',
        'roll_weight' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];
}
