<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonsterMarkRefinement extends Model
{
    protected $fillable = [
        'character_id',
        'request_token',
        'stat',
        'points',
        'mark_cost',
        'consumed_marks',
    ];

    protected $casts = [
        'points' => 'integer',
        'mark_cost' => 'integer',
        'consumed_marks' => 'array',
    ];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }
}
