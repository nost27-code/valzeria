<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EquipmentDecompositionLog extends Model
{
    protected $fillable = [
        'character_id',
        'equipment_instance_id',
        'equipment_master_id',
        'equipment_name',
        'rank',
        'enhancement_level',
        'obtained_materials',
    ];

    protected $casts = [
        'obtained_materials' => 'array',
        'enhancement_level' => 'integer',
    ];
}
