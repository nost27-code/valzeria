<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NationMembershipCooldown extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'global_join_blocked_until' => 'datetime',
            'same_nation_blocked_until' => 'datetime',
            'ruler_refound_blocked_until' => 'datetime',
        ];
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function sameNation(): BelongsTo
    {
        return $this->belongsTo(Nation::class, 'same_nation_id');
    }
}
