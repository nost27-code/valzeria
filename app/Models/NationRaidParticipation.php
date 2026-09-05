<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class NationRaidParticipation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'account_id' => 'integer',
            'character_id_snapshot' => 'integer',
            'nation_id_snapshot' => 'integer',
            'is_nation_eligible' => 'boolean',
            'is_late_entry' => 'boolean',
            'published_active_count' => 'integer',
            'started_active_count' => 'integer',
            'reference_active_count' => 'integer',
            'resolved_sorties' => 'integer',
            'personal_damage_total' => 'integer',
            'max_action_damage' => 'integer',
            'final_result_snapshot' => 'array',
            'first_resolved_at' => 'datetime',
            'last_resolved_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(NationRaidEvent::class, 'event_id');
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }

    public function dailyUsages(): HasMany
    {
        return $this->hasMany(NationRaidDailyUsage::class, 'participation_id');
    }

    public function battleResults(): HasMany
    {
        return $this->hasMany(NationRaidBattleResult::class, 'participation_id');
    }
}
