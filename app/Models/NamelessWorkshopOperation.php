<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NamelessWorkshopOperation extends Model
{
    protected $fillable = ['character_id', 'request_uuid', 'action', 'payload_hash', 'result'];

    protected $casts = ['result' => 'array'];
}
