<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlayerValmonEgg extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_hatched' => 'boolean',
        'is_lost' => 'boolean',
        'found_at' => 'datetime',
        'hatched_at' => 'datetime',
        'lost_at' => 'datetime',
    ];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function master()
    {
        return $this->belongsTo(ValmonMaster::class, 'valmon_master_id');
    }
}
