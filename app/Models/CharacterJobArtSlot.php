<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CharacterJobArtSlot extends Model
{
    protected $fillable = [
        'character_id',
        'battle_context',
        'slot_no',
        'skill_id',
        'activation_policy',
    ];

    protected $casts = [
        'slot_no' => 'integer',
        'activation_policy' => 'string',
    ];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function skill()
    {
        return $this->belongsTo(Skill::class);
    }
}
