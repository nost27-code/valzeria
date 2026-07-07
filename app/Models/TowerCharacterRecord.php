<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TowerCharacterRecord extends Model
{
    protected $guarded = [];

    protected $casts = [
        'best_cleared_floor' => 'integer',
        'best_failed_floor' => 'integer',
        'total_runs' => 'integer',
        'total_wins' => 'integer',
        'total_defeats' => 'integer',
        'total_returns' => 'integer',
        'achieved_at' => 'datetime',
    ];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function bestRun()
    {
        return $this->belongsTo(TowerRun::class, 'best_run_id');
    }
}
