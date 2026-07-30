<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharacterIconDesignMessageAttachment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'size' => 'integer',
        'position' => 'integer',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(CharacterIconDesignMessage::class, 'character_icon_design_message_id');
    }
}
