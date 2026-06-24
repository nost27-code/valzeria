<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TopUpdate extends Model
{
    protected $guarded = [];

    protected $casts = [
        'published_on' => 'date',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];
}
