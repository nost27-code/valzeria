<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CharacterRegionDungeonRun extends Model
{
    protected $guarded = [];

    protected $casts = [
        'entered_at' => 'datetime',
        'ended_at' => 'datetime',
        'public_log_sent_at' => 'datetime',
        'new_danger_record' => 'boolean',
        'metadata' => 'array',
        'total_exp' => 'integer',
        'total_job_exp' => 'integer',
        'max_danger_rate' => 'integer',
        'max_chain_count' => 'integer',
    ];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function area()
    {
        return $this->belongsTo(Area::class);
    }
}
