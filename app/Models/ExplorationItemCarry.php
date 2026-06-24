<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExplorationItemCarry extends Model
{
    protected $fillable = [
        'character_id',
        'area_id',
        'item_id',
        'carried_count',
        'used_count',
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
