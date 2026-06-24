<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JobSkill extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_class_id',
        'skill_id',
        'level_required',
    ];

    public function jobClass()
    {
        return $this->belongsTo(JobClass::class);
    }

    public function skill()
    {
        return $this->belongsTo(Skill::class);
    }
}
