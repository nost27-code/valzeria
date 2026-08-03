<?php

namespace App\Models;

use App\Services\SchemaStateService;
use Illuminate\Database\Eloquent\Model;

class CharacterNotification extends Model
{
    protected $guarded = [];

    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
        'expires_at' => 'datetime',
        'priority' => 'integer',
    ];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    public function scopeActive($query)
    {
        if (! app(SchemaStateService::class)->hasColumn('character_notifications', 'expires_at')) {
            return $query;
        }

        return $query->where(function ($q) {
            $q->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }

    public function markAsRead(): void
    {
        if ($this->read_at === null) {
            $this->forceFill(['read_at' => now()])->save();
        }
    }
}
