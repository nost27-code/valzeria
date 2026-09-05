<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class NationRaidBattleResult extends Model
{
    public const STATUS_STARTED = 'started';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_ABORTED = 'aborted';

    public const STATUS_REFUNDED = 'refunded';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'account_id' => 'integer',
            'raid_day' => 'integer',
            'day_sortie_no' => 'integer',
            'event_sortie_no' => 'integer',
            'target_cycle_no' => 'integer',
            'target_stage_no' => 'integer',
            'target_echo_no' => 'integer',
            'target_parameter_snapshot' => 'array',
            'killer_raw_rate' => 'float',
            'killer_effective_rate' => 'float',
            'turn_count' => 'integer',
            'calculated_damage_total' => 'integer',
            'applied_damage_total' => 'integer',
            'coordination_damage_total' => 'integer',
            'nation_damage_total' => 'integer',
            'max_action_damage' => 'integer',
            'job_art_slots_snapshot' => 'array',
            'turn_log' => 'array',
            'damage_segments' => 'array',
            'summary' => 'array',
            'settlement_attempts' => 'integer',
            'started_at' => 'datetime',
            'resolution_deadline_at' => 'datetime',
            'resolved_at' => 'datetime',
            'aborted_at' => 'datetime',
            'refunded_at' => 'datetime',
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
