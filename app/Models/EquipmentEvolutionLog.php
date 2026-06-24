<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EquipmentEvolutionLog extends Model
{
    protected $fillable = [
        'character_id',
        'recipe_type',
        'recipe_id',
        'before_equipment_id',
        'after_equipment_id',
        'consumed_equipment_count',
        'consumed_materials',
        'created_equipment_instance_id',
    ];

    protected $casts = [
        'consumed_materials' => 'array',
    ];
}
