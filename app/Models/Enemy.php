<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Enemy extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'is_boss' => 'boolean',
        'is_stat_locked' => 'boolean',
        'generated_at' => 'datetime',
    ];

    public function area()
    {
        return $this->belongsTo(Area::class);
    }

    public function drops()
    {
        return $this->hasMany(EnemyDrop::class);
    }
}
