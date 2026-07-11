<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlayerLifecycleEvent extends Model
{
    protected $fillable = ['user_id', 'character_id', 'event_name', 'event_key', 'metadata', 'occurred_at'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'occurred_at' => 'datetime'];
    }
}
