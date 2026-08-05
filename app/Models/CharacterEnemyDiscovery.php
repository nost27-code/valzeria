<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CharacterEnemyDiscovery extends Model
{
    protected $fillable = [
        'character_id',
        'enemy_id',
        'first_encountered_at',
        'first_defeated_at',
        'last_defeated_at',
        'defeat_count',
    ];

    protected $casts = [
        'first_encountered_at' => 'datetime',
        'first_defeated_at' => 'datetime',
        'last_defeated_at' => 'datetime',
        'defeat_count' => 'integer',
    ];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function enemy()
    {
        return $this->belongsTo(Enemy::class);
    }
}
