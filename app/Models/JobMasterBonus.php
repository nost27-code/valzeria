<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobMasterBonus extends Model
{
    protected $guarded = [];

    public function job()
    {
        return $this->belongsTo(JobClass::class, 'job_id');
    }
}
