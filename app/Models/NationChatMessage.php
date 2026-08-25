<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class NationChatMessage extends Model
{
    protected $guarded = [];

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
