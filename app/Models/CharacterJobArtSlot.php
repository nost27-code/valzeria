<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CharacterJobArtSlot extends Model
{
    protected $fillable = [
        'character_id',
        'slot_no',
        'skill_id',
    ];

    protected $casts = [
        'slot_no' => 'integer',
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
