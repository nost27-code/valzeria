<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CharacterCityDiscovery extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'rumored_at' => 'datetime',
        'discovered_at' => 'datetime',
    ];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }
}
