<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KisekiTransaction extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'amount' => 'integer',
        'enemy_level' => 'integer',
        'character_level' => 'integer',
        'daily_dropped_count' => 'integer',
    ];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function area()
    {
        return $this->belongsTo(Area::class);
    }

    public function enemy()
    {
        return $this->belongsTo(Enemy::class);
    }
}
