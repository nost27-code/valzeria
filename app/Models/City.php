<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class City extends Model
{
    protected $fillable = [
        'name',
        'description',
        'recommended_level_min',
        'recommended_level_max',
        'sort_order',
        'unlock_condition_type',
        'unlock_condition_value',
        'is_initial',
    ];

    public function areas()
    {
        return $this->hasMany(Area::class);
    }
}
