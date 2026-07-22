<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MapExplorationResult extends Model
{
    protected $guarded = [];
    protected $casts = ['monster_variants_json' => 'array', 'drops_json' => 'array'];
}
