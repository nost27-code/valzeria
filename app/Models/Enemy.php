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
        'map_biome_tags' => 'array',
        'map_normal_eligible' => 'boolean',
        'map_boss_eligible' => 'boolean',
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

    public function actions()
    {
        return $this->hasMany(EnemyAction::class)->orderBy('sort_order')->orderBy('id');
    }
}
