<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TowerFloorMaster extends Model
{
    protected $table = 'tower_floor_master';

    protected $guarded = [];

    protected $casts = [
        'floor' => 'integer',
        'stamina_cost' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];
}
