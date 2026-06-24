<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CharacterTitle extends Model
{
    use HasFactory;

    protected $fillable = [
        'character_id', 'title_id', 'is_equipped',
    ];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function title()
    {
        return $this->belongsTo(Title::class);
    }
}
