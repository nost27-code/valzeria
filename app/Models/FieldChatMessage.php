<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldChatMessage extends Model
{
    protected $fillable = [
        'character_id',
        'plane',
        'x',
        'y',
        'body',
        'zone',
    ];

    protected $casts = [
        'x' => 'integer',
        'y' => 'integer',
    ];

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
