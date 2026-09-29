<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharacterFieldPosition extends Model
{
    protected $fillable = [
        'character_id',
        'plane',
        'x',
        'y',
        'facing',
        'moved_at',
    ];

    protected $casts = [
        'x' => 'integer',
        'y' => 'integer',
        'facing' => 'integer',
        'moved_at' => 'datetime',
    ];

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
