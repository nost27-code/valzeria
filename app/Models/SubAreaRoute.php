<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubAreaRoute extends Model
{
    protected $guarded = [];

    protected $casts = [
        'discovery_chance' => 'float',
        'is_enabled' => 'boolean',
    ];

    public function subArea()
    {
        return $this->belongsTo(SubArea::class);
    }

    public function sourceArea()
    {
        return $this->belongsTo(Area::class, 'source_area_id');
    }

    public function requiredBossArea()
    {
        return $this->belongsTo(Area::class, 'required_boss_cleared_area_id');
    }

    public function discoveries()
    {
        return $this->hasMany(CharacterSubAreaRouteDiscovery::class);
    }
}
