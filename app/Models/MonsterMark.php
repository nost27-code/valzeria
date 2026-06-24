<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonsterMark extends Model
{
    protected $fillable = [
        'enemy_id',
        'mark_name',
        'bonus_stat',
        'bonus_per_level',
        'required_per_level',
        'max_level',
        'drop_rate',
        'is_active',
    ];

    protected $casts = [
        'bonus_per_level' => 'integer',
        'required_per_level' => 'integer',
        'max_level' => 'integer',
        'drop_rate' => 'float',
        'is_active' => 'boolean',
    ];

    public function enemy()
    {
        return $this->belongsTo(Enemy::class);
    }

    public function characterMarks()
    {
        return $this->hasMany(CharacterMonsterMark::class);
    }
}
