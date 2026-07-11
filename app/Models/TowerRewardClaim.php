<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TowerRewardClaim extends Model
{
    protected $fillable = [
        'character_id',
        'tower_key',
        'floor',
        'reward_type',
        'status',
        'selected_item_id',
        'character_item_id',
        'asset_type',
        'asset_path',
        'metadata',
        'claimed_at',
    ];

    protected $casts = [
        'floor' => 'integer',
        'metadata' => 'array',
        'claimed_at' => 'datetime',
    ];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function selectedItem()
    {
        return $this->belongsTo(Item::class, 'selected_item_id');
    }

    public function characterItem()
    {
        return $this->belongsTo(CharacterItem::class);
    }
}
