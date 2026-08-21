<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SixHeroSeason extends Model
{
    protected $fillable = [
        'season_key',
        'starts_at',
        'ends_at',
        'finalized_at',
        'ranking_initialized_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'finalized_at' => 'datetime',
            'ranking_initialized_at' => 'datetime',
        ];
    }

    public function rankings(): HasMany
    {
        return $this->hasMany(SixHeroRanking::class, 'season_id');
    }

    public function battleLogs(): HasMany
    {
        return $this->hasMany(SixHeroBattleLog::class, 'season_id');
    }

    public function champions(): HasMany
    {
        return $this->hasMany(SixHeroChampion::class, 'season_id');
    }

    public function scopeContaining(Builder $query, CarbonInterface $at): Builder
    {
        return $query
            ->where('starts_at', '<=', $at)
            ->where('ends_at', '>', $at);
    }
}
