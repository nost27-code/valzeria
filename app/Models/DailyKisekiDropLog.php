<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DailyKisekiDropLog extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'drop_date' => 'date',
        'dropped_count' => 'integer',
    ];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }
}
