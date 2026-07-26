<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WeeklyWinRankingSeason extends Model
{
    protected $guarded = [];

    protected $casts = [
        'week_started_at' => 'datetime',
        'week_ended_at' => 'datetime',
        'participant_count' => 'integer',
        'rewarded_count' => 'integer',
        'total_free_kiseki' => 'integer',
        'finalized_at' => 'datetime',
    ];

    public function records()
    {
        return $this->hasMany(WeeklyWinRankingRecord::class, 'season_id');
    }
}
