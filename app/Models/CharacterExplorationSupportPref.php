<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CharacterExplorationSupportPref extends Model
{
    protected $guarded = [];

    protected $casts = [
        'auto_renew' => 'boolean',
    ];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }
}
