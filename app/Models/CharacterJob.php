<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CharacterJob extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_mastered' => 'boolean',
        'mastered_at' => 'datetime',
    ];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function jobClass()
    {
        return $this->belongsTo(JobClass::class, 'job_class_id');
    }
}
