<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CharacterExplorationState extends Model
{
    protected $guarded = [];

    protected $casts = [
        'danger_rate' => 'integer',
        'last_treasure_band' => 'integer',
        'treasure_found_count' => 'integer',
        'secret_realm_found_count' => 'integer',
        'dungeon_lord_encountered' => 'boolean',
        'valmon_material_found' => 'boolean',
        'rescue_insurance_enabled' => 'boolean',
        'started_at' => 'datetime',
    ];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function area()
    {
        return $this->belongsTo(Area::class);
    }
}
