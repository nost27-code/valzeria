<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CharacterRegionDungeonRecord extends Model
{
    protected $guarded = [];

    protected $casts = [
        'best_danger_rate' => 'integer',
        'best_chain_count' => 'integer',
        'best_total_exp' => 'integer',
        'best_danger_at' => 'datetime',
        'best_chain_at' => 'datetime',
        'best_total_exp_at' => 'datetime',
    ];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }
}
