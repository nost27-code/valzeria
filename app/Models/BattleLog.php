<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BattleLog extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'job_exp_gained' => 'integer',
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
