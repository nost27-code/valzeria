<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class NationRaidEvent extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FINALIZING = 'finalizing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const RESERVED_STATUSES = [self::STATUS_SCHEDULED, self::STATUS_ACTIVE, self::STATUS_FINALIZING];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'announced_at' => 'datetime',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'activated_at' => 'datetime',
            'stage10_reached_at' => 'datetime',
            'completed_at' => 'datetime',
            'sorties_paused_at' => 'datetime',
            'finalization_started_at' => 'datetime',
            'finalized_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'stage_count' => 'integer',
            'cycle_max_hp' => 'integer',
            'total_target_hp' => 'integer',
            'current_cycle_no' => 'integer',
            'echo_defeated_count' => 'integer',
            'ruleset_snapshot' => 'array',
            'reward_policy_snapshot' => 'array',
            'final_standings_snapshot' => 'array',
            'published_nation_counts_snapshot' => 'array',
            'balance_approved_at' => 'datetime',
            'state_version' => 'integer',
        ];
    }

    public function scopeReserved(Builder $query): Builder
    {
        return $query->whereIn('status', self::RESERVED_STATUSES);
    }

    public function cycles(): HasMany
    {
        return $this->hasMany(NationRaidBossCycle::class, 'event_id');
    }

    public function participations(): HasMany
    {
        return $this->hasMany(NationRaidParticipation::class, 'event_id');
    }

    public function battleResults(): HasMany
    {
        return $this->hasMany(NationRaidBattleResult::class, 'event_id');
    }

    public function balanceApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'balance_approved_by_user_id');
    }

    public function acceptsNewSortiesAt(CarbonInterface $at): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->sorties_paused_at === null
            && $this->starts_at->lte($at)
            && $this->ends_at->gt($at);
    }

    public function raidDayAt(CarbonInterface $at): ?int
    {
        if ($at->lt($this->starts_at) || ! $at->lt($this->ends_at)) {
            return null;
        }

        return intdiv((int) $this->starts_at->diffInSeconds($at), 86_400) + 1;
    }
}
