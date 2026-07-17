<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityAnomalyCase extends Model
{
    public const STATUSES = ['detected', 'reviewing', 'cleared', 'actioned'];

    protected $guarded = [];

    protected $casts = [
        'evidence' => 'array',
        'detection_count' => 'integer',
        'first_detected_at' => 'datetime',
        'last_detected_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function events()
    {
        return $this->hasMany(SecurityAnomalyCaseEvent::class)->latest('created_at')->latest('id');
    }
}
