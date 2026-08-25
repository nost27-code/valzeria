<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NationActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'actor_character_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'target_character_id');
    }
}
