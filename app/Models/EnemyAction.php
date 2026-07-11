<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EnemyAction extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'can_use_on_first_turn' => 'boolean',
        'is_telegraphed' => 'boolean',
        'can_be_guarded' => 'boolean',
        'guard_reduction_rate' => 'float',
        'cancel_on_enemy_death' => 'boolean',
        'guarantee_first_use' => 'boolean',
    ];

    public function enemy()
    {
        return $this->belongsTo(Enemy::class);
    }
}
