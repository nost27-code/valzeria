<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChampState extends Model
{
    protected $guarded = [];

    protected $casts = [
        'appointed_at' => 'datetime',
        'affinity_physical' => 'float',
        'affinity_speed'    => 'float',
        'affinity_magical'  => 'float',
        'normal_attack_type' => 'string',
    ];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }
}
