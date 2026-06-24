<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CharacterConsumableItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }
}
