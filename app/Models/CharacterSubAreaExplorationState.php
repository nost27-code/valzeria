<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CharacterSubAreaExplorationState extends Model
{
    protected $guarded = [];

    protected $casts = [
        'exploration_point' => 'integer',
        'chain_count' => 'integer',
        'danger_rate' => 'integer',
        'sub_area_lord_encountered' => 'boolean',
        'started_at' => 'datetime',
    ];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function subArea()
    {
        return $this->belongsTo(SubArea::class);
    }

    public function route()
    {
        return $this->belongsTo(SubAreaRoute::class, 'sub_area_route_id');
    }
}
