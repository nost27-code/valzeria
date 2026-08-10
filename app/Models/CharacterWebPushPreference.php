<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CharacterWebPushPreference extends Model
{
    protected $guarded = [];

    protected $casts = [
        'enabled_types' => 'array',
    ];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }
}
