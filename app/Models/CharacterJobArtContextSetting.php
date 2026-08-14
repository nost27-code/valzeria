<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CharacterJobArtContextSetting extends Model
{
    protected $fillable = [
        'character_id',
        'battle_context',
        'sp_policy',
    ];

    protected $casts = [
        'character_id' => 'integer',
        'sp_policy' => 'string',
    ];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }
}
