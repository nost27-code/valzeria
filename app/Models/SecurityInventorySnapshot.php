<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityInventorySnapshot extends Model
{
    protected $primaryKey = 'character_id';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'equipment_count' => 'integer',
        'material_quantity' => 'integer',
        'captured_at' => 'datetime',
    ];
}
