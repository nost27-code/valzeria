<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NationMembership extends Model
{
    public const ROLES = ['ruler', 'chancellor', 'marshal', 'logistics_officer', 'citizen'];

    public const ASSIGNABLE_ROLES = ['chancellor', 'marshal', 'logistics_officer', 'citizen'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime'];
    }

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function isRuler(): bool
    {
        return $this->role === 'ruler';
    }

    public function roleLabel(?Nation $nation = null): string
    {
        return match ($this->role) {
            'ruler' => ($nation ?? $this->nation)->ruler_title,
            'chancellor' => '宰相',
            'marshal' => '元帥',
            'logistics_officer' => '兵站官',
            default => '国民',
        };
    }
}
