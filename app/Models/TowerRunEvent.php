<?php

namespace App\Models;

use App\Support\PlayerStatLabel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class TowerRunEvent extends Model
{
    protected $guarded = [];

    protected $casts = [
        'floor' => 'integer',
        'damage_taken' => 'integer',
        'hp_after' => 'integer',
        'mp_after' => 'integer',
        'gold_delta' => 'integer',
        'stamina_delta' => 'integer',
        'exp_gained' => 'integer',
        'job_exp_gained' => 'integer',
        'metadata' => 'array',
    ];

    public function towerRun()
    {
        return $this->belongsTo(TowerRun::class);
    }

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    /**
     * @return Collection<int, string>
     */
    public function playerFacingBattleLogs(): Collection
    {
        return collect($this->metadata['logs'] ?? [])
            ->filter(static fn (mixed $log): bool => is_string($log))
            ->map(static fn (string $log): string => PlayerStatLabel::inText($log))
            ->values();
    }
}
