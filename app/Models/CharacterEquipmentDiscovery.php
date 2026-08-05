<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CharacterEquipmentDiscovery extends Model
{
    protected $fillable = [
        'character_id',
        'item_id',
        'discovered_at',
    ];

    protected $casts = [
        'discovered_at' => 'datetime',
    ];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }
}
