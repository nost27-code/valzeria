<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlayerValmon extends Model
{
    protected $guarded = [];

    protected $casts = [
        'level' => 'integer',
        'exp' => 'integer',
        'affection' => 'integer',
        'is_partner' => 'boolean',
        'obtained_at' => 'datetime',
    ];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function master()
    {
        return $this->belongsTo(ValmonMaster::class, 'valmon_master_id');
    }

    public function displayName(): string
    {
        return $this->nickname ?: ($this->master?->name ?? 'ヴァルモン');
    }
}
