<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ArenaRanking extends Model
{
    use HasFactory;

    protected $fillable = [
        'character_id',
        'rank',
        'wins',
        'losses',
    ];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }
}
