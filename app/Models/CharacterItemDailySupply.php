<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CharacterItemDailySupply extends Model
{
    protected $fillable = [
        'character_id',
        'item_id',
        'claimed_on',
        'supplied_count',
        'stocked_count',
    ];

    protected $casts = [
        'claimed_on' => 'date',
        'supplied_count' => 'integer',
        'stocked_count' => 'integer',
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
