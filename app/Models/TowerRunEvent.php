<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TowerRunEvent extends Model
{
    protected $guarded = [];

    protected $casts = [
        'floor' => 'integer',
        'damage_taken' => 'integer',
        'hp_after' => 'integer',
        'mp_after' => 'integer',
        'gold_delta' => 'integer',
        'stamina_delta' => 'integer',
        'exp_gained' => 'integer',
        'job_exp_gained' => 'integer',
        'metadata' => 'array',
    ];

    public function towerRun()
    {
        return $this->belongsTo(TowerRun::class);
    }

    public function character()
    {
        return $this->belongsTo(Character::class);
    }
}
