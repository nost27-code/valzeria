<?php

namespace App\Models;

use App\Enums\NationType;
use App\Services\Nation\NationEmblemCatalog;
use App\Services\Nation\NationHeaderBackgroundCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Nation extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISBAND_PENDING = 'disband_pending';

    public const STATUS_DISBANDED = 'disbanded';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'treasury_points' => 'integer',
            'development_exp' => 'integer',
            'decoration_settings' => 'array',
            'founded_at' => 'datetime',
            'loss_protected_until' => 'datetime',
            'recruitment_enabled' => 'boolean',
            'is_hidden' => 'boolean',
            'dissolution_requested_at' => 'datetime',
            'dissolution_effective_at' => 'datetime',
            'dissolution_recruitment_was_enabled' => 'boolean',
            'disbanded_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->where('is_hidden', false);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(NationMembership::class);
    }

    public function rulerMembership(): HasOne
    {
        return $this->hasOne(NationMembership::class)->where('role', 'ruler');
    }

    public function joinApplications(): HasMany
    {
        return $this->hasMany(NationJoinApplication::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(NationActivityLog::class);
    }

    public function chatMessages(): HasMany
    {
        return $this->hasMany(NationChatMessage::class);
    }

    public function facilities(): HasMany
    {
        return $this->hasMany(NationFacility::class);
    }

    public function resourceTransactions(): HasMany
    {
        return $this->hasMany(NationResourceTransaction::class);
    }

    public function goals(): HasMany
    {
        return $this->hasMany(NationGoal::class);
    }

    public function wantedMaterials(): HasMany
    {
        return $this->hasMany(NationWantedMaterial::class);
    }

    public function achievements(): HasMany
    {
        return $this->hasMany(NationAchievement::class);
    }

    public function warPreparationPresets(): HasMany
    {
        return $this->hasMany(NationWarPreparationPreset::class);
    }

    public function dissolutionRequester(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'dissolution_requested_by_character_id');
    }

    public function type(): NationType
    {
        return NationType::tryFrom((string) $this->nation_type) ?? NationType::KINGDOM;
    }

    public function getDisplayNameAttribute(): string
    {
        return (string) $this->name.$this->type()->label();
    }

    public function getNationTypeLabelAttribute(): string
    {
        return $this->type()->label();
    }

    public function getRulerTitleAttribute(): string
    {
        return $this->type()->rulerTitle();
    }

    public function getEmblemAttribute(): array
    {
        return app(NationEmblemCatalog::class)->get($this->emblem_key);
    }

    public function getHeaderBackgroundAttribute(): array
    {
        return app(NationHeaderBackgroundCatalog::class)->get($this->header_background_key);
    }
}
