<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChampBattleLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_champ_defeated' => 'boolean',
    ];

    public function champCharacter()
    {
        return $this->belongsTo(Character::class, 'champ_character_id');
    }

    public function challenger()
    {
        return $this->belongsTo(Character::class, 'challenger_character_id');
    }
}
