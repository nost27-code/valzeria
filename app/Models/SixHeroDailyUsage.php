<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SixHeroDailyUsage extends Model
{
    protected $fillable = [
        'character_id',
        'usage_date',
        'official_attempts',
        'official_attempts_by_room',
    ];

    protected function casts(): array
    {
        return [
            'usage_date' => 'date',
            'official_attempts' => 'integer',
            'official_attempts_by_room' => 'array',
        ];
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
