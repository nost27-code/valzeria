<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CharacterJobArtContextSetting extends Model
{
    protected $fillable = [
        'character_id',
        'battle_context',
        'sp_policy',
        'strategy_mode',
        'strategy_settings',
    ];

    protected $casts = [
        'character_id' => 'integer',
        'sp_policy' => 'string',
        'strategy_mode' => 'string',
        'strategy_settings' => 'array',
    ];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }
}
