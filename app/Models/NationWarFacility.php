<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NationWarFacility extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['destroyed_at' => 'datetime', 'rebuild_completes_at' => 'datetime']; }
}
