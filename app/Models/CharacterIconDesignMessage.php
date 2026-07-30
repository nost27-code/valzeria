<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CharacterIconDesignMessage extends Model
{
    protected $guarded = [];

    protected $casts = [
        'read_by_player_at' => 'datetime',
        'read_by_admin_at' => 'datetime',
    ];

    public function designRequest(): BelongsTo
    {
        return $this->belongsTo(CharacterIconDesignRequest::class, 'character_icon_design_request_id');
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(CharacterIconDesignMessageAttachment::class)
            ->orderBy('position')
            ->orderBy('id');
    }
}
