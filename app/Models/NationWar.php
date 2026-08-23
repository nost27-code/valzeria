<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NationWar extends Model
{
    public const LIVE_STATUSES = ['reserved', 'preparing', 'active'];
    protected $guarded = [];
    protected function casts(): array
    {
        return [
            'declared_at' => 'datetime', 'preparation_starts_at' => 'datetime', 'starts_at' => 'datetime',
            'ends_at' => 'datetime', 'resolved_at' => 'datetime', 'resolution_snapshot' => 'array',
        ];
    }
    public function sides(): HasMany { return $this->hasMany(NationWarSide::class); }
    public function facilities(): HasMany { return $this->hasMany(NationWarFacility::class); }
    public function participants(): HasMany { return $this->hasMany(NationWarParticipant::class); }
    public function declaringNation(): BelongsTo { return $this->belongsTo(Nation::class, 'declaring_nation_id'); }
    public function defendingNation(): BelongsTo { return $this->belongsTo(Nation::class, 'defending_nation_id'); }
    public function winnerNation(): BelongsTo { return $this->belongsTo(Nation::class, 'winner_nation_id'); }
}
