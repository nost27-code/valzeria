<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityLoginObservation extends Model
{
    protected $guarded = [];

    protected $casts = [
        'observed_date' => 'date',
        'first_observed_at' => 'datetime',
        'last_observed_at' => 'datetime',
        'observation_count' => 'integer',
    ];
}
