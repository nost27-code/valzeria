<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExternalFeedItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'published_at' => 'datetime',
        'notified_at' => 'datetime',
        'raw' => 'array',
    ];
}
