<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TownMapRegistration extends Model
{
    public const VISIBILITY_OWNER = 'owner';

    public const VISIBILITY_NATION = 'nation';

    public const VISIBILITY_ALL = 'all';

    public const VISIBILITY_SCOPES = [
        self::VISIBILITY_OWNER,
        self::VISIBILITY_NATION,
        self::VISIBILITY_ALL,
    ];

    protected $guarded = [];
    protected $casts = ['survey_started_at' => 'datetime', 'survey_completed_at' => 'datetime', 'published_at' => 'datetime', 'expires_at' => 'datetime', 'entry_fee_changed_at' => 'datetime'];
    public function map() { return $this->belongsTo(ExplorationMap::class, 'map_id'); }
    public function town() { return $this->belongsTo(City::class, 'town_id'); }
    public function publicationNation(): BelongsTo { return $this->belongsTo(Nation::class, 'nation_id_snapshot'); }
    public function visibilityScope(): string
    {
        $scope = (string) ($this->visibility_scope ?? '');

        return $scope === '' ? self::VISIBILITY_ALL : $scope;
    }
    public function visibilityLabel(): string
    {
        return match ($this->visibilityScope()) {
            self::VISIBILITY_OWNER => '自分だけ',
            self::VISIBILITY_NATION => $this->publicationNation
                ? $this->publicationNation->display_name.'のみ'
                : '公開時の国家のみ',
            self::VISIBILITY_ALL => 'すべての冒険者',
            default => '公開範囲不明',
        };
    }
    public function isPublished(): bool { return $this->status === 'published' && $this->published_at !== null; }
    public function isWithdrawn(): bool { return $this->status === 'withdrawn'; }
    public function isOpen(): bool { return $this->isPublished() && $this->remaining_explorations > 0 && $this->expires_at?->isFuture(); }
    public function closedAt(): mixed
    {
        if ($this->isWithdrawn()) return $this->updated_at;
        if (!$this->isPublished() || $this->isOpen()) return null;

        return $this->remaining_explorations <= 0 ? $this->updated_at : $this->expires_at;
    }
    public function isRecentlyClosed(): bool
    {
        $closedAt = $this->closedAt();

        return $closedAt !== null && $closedAt->greaterThan(now()->subHours((int) config('exploration_maps.closed_map_display_hours', 6)));
    }
}
