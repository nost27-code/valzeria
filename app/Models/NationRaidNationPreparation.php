<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class NationRaidNationPreparation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'nation_id_snapshot' => 'integer',
            'reference_active_count' => 'integer',
            'contribution_target' => 'integer',
            'contribution_count' => 'integer',
            'readiness_percent' => 'integer',
            'earned_daily_free_grant' => 'integer',
            'earned_free_balance_cap' => 'integer',
            'applied_daily_free_grant' => 'integer',
            'applied_free_balance_cap' => 'integer',
            'benefits_held' => 'boolean',
            'frozen_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(NationRaidEvent::class, 'event_id');
    }

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(NationRaidPreparationMember::class, 'preparation_id');
    }
}
