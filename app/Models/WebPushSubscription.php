<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebPushSubscription extends Model
{
    protected $guarded = [];

    protected $casts = [
        'endpoint' => 'encrypted',
        'public_key' => 'encrypted',
        'auth_token' => 'encrypted',
        'last_notification_id' => 'integer',
    ];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }
}
