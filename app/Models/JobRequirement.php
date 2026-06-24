<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobRequirement extends Model
{
    protected $guarded = [];

    public function job()
    {
        return $this->belongsTo(JobClass::class, 'job_id');
    }

    public function requiredJob()
    {
        return $this->belongsTo(JobClass::class, 'required_job_id');
    }
}
