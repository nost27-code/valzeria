<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ValmonSpawnRegion extends Model
{
    protected $guarded = [];

    protected $casts = [
        'spawn_weight' => 'integer',
        'is_active' => 'boolean',
    ];

    public function valmonMaster()
    {
        return $this->belongsTo(ValmonMaster::class);
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }
}
