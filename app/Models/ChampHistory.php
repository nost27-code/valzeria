<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChampHistory extends Model
{
    protected $guarded = [];

    protected $casts = [
        'appointed_at' => 'datetime',
        'defeated_at' => 'datetime',
    ];
}
