<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class NationRaidInvasionDamage extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_RECOVERED = 'recovered';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'nation_id_snapshot' => 'integer',
            'reference_active_count' => 'integer',
            'result_progress_bps' => 'integer',
            'readiness_percent' => 'integer',
            'effective_participant_count' => 'integer',
            'participation_percent' => 'integer',
            'base_damage' => 'integer',
            'readiness_mitigation' => 'integer',
            'participation_mitigation' => 'integer',
            'final_damage' => 'integer',
            'reconstruction_required' => 'integer',
            'reconstruction_completed' => 'integer',
            'result_snapshot' => 'array',
            'recovered_at' => 'datetime',
        ];
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->whereColumn('reconstruction_completed', '<', 'reconstruction_required');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(NationRaidEvent::class, 'event_id');
    }

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(NationRaidReconstructionContribution::class, 'invasion_damage_id');
    }
}
