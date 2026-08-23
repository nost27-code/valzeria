<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NationWarSortieLog extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['summary' => 'array', 'cannon_direct_hit' => 'boolean', 'died' => 'boolean']; }
}
