<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TowerWeeklyRecord extends Model
{
    protected $guarded = [];

    protected $casts = [
        'best_cleared_floor' => 'integer',
        'best_failed_floor' => 'integer',
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
