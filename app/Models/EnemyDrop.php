<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EnemyDrop extends Model
{
    protected $fillable = [
        'enemy_id',
        'item_id',
        'drop_rate',
        'min_character_level',
        'max_character_level',
        'is_active',
    ];

    protected $casts = [
        'drop_rate' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function enemy()
    {
        return $this->belongsTo(Enemy::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }
}
