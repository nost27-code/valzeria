<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WeaponTraitOperationLog extends Model
{
    protected $fillable = [
        'character_id',
        'operation',
        'base_character_item_id',
        'material_character_item_id',
        'before_snapshot',
        'material_snapshot',
        'after_snapshot',
        'gold_cost',
    ];

    protected $casts = [
        'before_snapshot' => 'array',
        'material_snapshot' => 'array',
        'after_snapshot' => 'array',
        'gold_cost' => 'integer',
    ];
}
