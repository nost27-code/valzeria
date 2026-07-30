<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CharacterIconDesignRequest extends Model
{
    protected $guarded = [];

    protected $casts = [
        'price_kiseki' => 'integer',
        'free_kiseki_spent' => 'integer',
        'paid_kiseki_spent' => 'integer',
        'form_data' => 'array',
        'permit_granted_at' => 'datetime',
        'purchased_at' => 'datetime',
        'submitted_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function permitGrantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'permit_granted_by_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(CharacterIconDesignMessage::class);
    }

    public function statusLabel(): string
    {
        return (string) (config("character_icon_design.statuses.{$this->status}") ?? $this->status);
    }

    public function isChatOpen(): bool
    {
        return in_array($this->status, config('character_icon_design.chat_open_statuses', []), true);
    }
}
