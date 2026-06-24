<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubArea extends Model
{
    protected $guarded = [];

    protected $casts = [
        'world_first_discovered_at' => 'datetime',
        'is_enabled' => 'boolean',
    ];

    public function routes()
    {
        return $this->hasMany(SubAreaRoute::class);
    }

    public function worldFirstCharacter()
    {
        return $this->belongsTo(Character::class, 'world_first_character_id');
    }
}
