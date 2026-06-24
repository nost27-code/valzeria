<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AreaDiscoveryLink extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'required_development_point' => 'integer',
        'requires_boss_defeated' => 'boolean',
        'sort_order' => 'integer',
    ];
}
