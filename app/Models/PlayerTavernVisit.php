<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlayerTavernVisit extends Model
{
    protected $guarded = [];

    protected $casts = [
        'last_visited_at' => 'datetime',
    ];
}
