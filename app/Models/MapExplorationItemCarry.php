<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MapExplorationItemCarry extends Model
{
    protected $fillable = [
        'character_id',
        'registration_id',
        'item_id',
        'carried_count',
        'used_count',
    ];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function registration()
    {
        return $this->belongsTo(TownMapRegistration::class, 'registration_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }
}
