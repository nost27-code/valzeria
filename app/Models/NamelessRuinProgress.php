<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NamelessRuinProgress extends Model
{
    protected $table = 'nameless_ruin_progress';

    protected $fillable = ['character_id', 'zone_key', 'unlocked_depth'];

    protected $casts = ['unlocked_depth' => 'integer'];
}
