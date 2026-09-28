<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CharacterMonsterMark extends Model
{
    protected $fillable = [
        'character_id',
        'monster_mark_id',
        'quantity',
        'spent_quantity',
        'unlocked_level',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'spent_quantity' => 'integer',
        'unlocked_level' => 'integer',
    ];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function monsterMark()
    {
        return $this->belongsTo(MonsterMark::class);
    }
}
