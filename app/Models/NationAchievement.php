<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NationAchievement extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'unlocked_at' => 'datetime',
            'metadata' => 'array',
            'display_position' => 'integer',
        ];
    }

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }
}
