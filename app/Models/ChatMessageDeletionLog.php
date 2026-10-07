<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatMessageDeletionLog extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'sent_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}
