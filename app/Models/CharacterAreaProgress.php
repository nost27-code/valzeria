<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CharacterAreaProgress extends Model
{
    use HasFactory;

    protected $table = 'character_area_progresses';

    protected $guarded = [];

    protected $casts = [
        'is_unlocked' => 'boolean',
        'boss_defeated' => 'boolean',
        'development_point' => 'integer',
        'unlocked_at' => 'datetime',
        'boss_defeated_at' => 'datetime',
        'rumored_at' => 'datetime',
        'discovered_at' => 'datetime',
        'cleared_at' => 'datetime',
    ];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function area()
    {
        return $this->belongsTo(Area::class);
    }
}
