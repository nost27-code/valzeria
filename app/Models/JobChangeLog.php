<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobChangeLog extends Model
{
    protected $guarded = [];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function fromJob()
    {
        return $this->belongsTo(JobClass::class, 'from_job_id');
    }

    public function toJob()
    {
        return $this->belongsTo(JobClass::class, 'to_job_id');
    }
}
