<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class NationRaidCoordinationParticipant extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'nation_id_snapshot' => 'integer',
            'character_id_snapshot' => 'integer',
            'window_joined_at' => 'datetime',
            'last_resolved_at' => 'datetime',
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
