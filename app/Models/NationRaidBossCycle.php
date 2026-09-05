<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class NationRaidBossCycle extends Model
{
    public const KIND_MAIN = 'main';

    public const KIND_ECHO = 'echo';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'cycle_no' => 'integer',
            'stage_no' => 'integer',
            'echo_no' => 'integer',
            'max_hp' => 'integer',
            'current_hp' => 'integer',
            'parameter_snapshot' => 'array',
            'started_at' => 'datetime',
            'defeated_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(NationRaidEvent::class, 'event_id');
    }
}
