<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class NationRaidDailyUsage extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'account_id' => 'integer',
            'raid_day' => 'integer',
            'used_count' => 'integer',
            'resolved_count' => 'integer',
            'refunded_count' => 'integer',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(NationRaidEvent::class, 'event_id');
    }

    public function participation(): BelongsTo
    {
        return $this->belongsTo(NationRaidParticipation::class, 'participation_id');
    }
}
