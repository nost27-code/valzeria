<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WeeklyWinRankingRecord extends Model
{
    protected $guarded = [];

    protected $casts = [
        'wins' => 'integer',
        'rank' => 'integer',
        'reward_free_kiseki' => 'integer',
        'is_reward_eligible' => 'boolean',
        'rewarded_at' => 'datetime',
    ];

    public function season()
    {
        return $this->belongsTo(WeeklyWinRankingSeason::class, 'season_id');
    }

    public function character()
    {
        return $this->belongsTo(Character::class);
    }
}
