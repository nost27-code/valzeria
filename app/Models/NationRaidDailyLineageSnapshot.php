<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class NationRaidDailyLineageSnapshot extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'raid_day' => 'integer',
            'adopted_sets_snapshot' => 'array',
            'vote_counts' => 'array',
            'votes_snapshot' => 'array',
            'determined_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(NationRaidEvent::class, 'event_id');
    }
}
