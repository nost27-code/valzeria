<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ValmonFeedLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'integer',
        'gained_exp' => 'integer',
        'gained_affection' => 'integer',
    ];
}
