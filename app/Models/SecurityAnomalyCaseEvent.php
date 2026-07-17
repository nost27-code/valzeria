<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityAnomalyCaseEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['created_at' => 'datetime'];

    public function anomalyCase()
    {
        return $this->belongsTo(SecurityAnomalyCase::class, 'security_anomaly_case_id');
    }

    public function adminUser()
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }
}
