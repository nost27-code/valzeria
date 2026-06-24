<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CharacterSubAreaRouteDiscovery extends Model
{
    protected $guarded = [];

    protected $casts = [
        'discovered_at' => 'datetime',
        'first_entered_at' => 'datetime',
        'first_cleared_at' => 'datetime',
    ];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function route()
    {
        return $this->belongsTo(SubAreaRoute::class, 'sub_area_route_id');
    }
}
