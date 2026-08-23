<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NationWarDailySortie extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['sortie_date' => 'date']; }
}
