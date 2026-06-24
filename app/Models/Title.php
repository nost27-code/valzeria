<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Title extends Model
{
    use HasFactory;

    protected $fillable = [
        'category', 'rarity', 'name', 'description', 'hint',
        'unlock_type', 'target_type', 'target_id', 'source_master',
        'display_order', 'is_hidden',
    ];

    public function characterTitles()
    {
        return $this->hasMany(CharacterTitle::class);
    }
}
