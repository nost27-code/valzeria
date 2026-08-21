<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameplayMetric extends Model
{
    public const TYPE_JOB_ART_BATTLE = 'job_art_battle';

    public const TYPE_EXPLORATION_REQUEST = 'exploration_request';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }
}
