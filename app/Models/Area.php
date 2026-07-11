<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Area extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'is_route_area' => 'boolean',
        'is_published' => 'boolean',
        'development_required_point' => 'integer',
    ];

    public function enemies()
    {
        return $this->hasMany(Enemy::class);
    }

    public function requiredArea()
    {
        return $this->belongsTo(Area::class, 'unlock_required_area_id');
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    public function discoveryLinksFrom()
    {
        return $this->hasMany(AreaDiscoveryLink::class, 'from_id')
            ->where('from_type', 'area');
    }
}
