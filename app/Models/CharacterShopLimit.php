<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CharacterShopLimit extends Model
{
    protected $guarded = [];

    protected $casts = [
        'limit_date' => 'date',
        'purchased_count' => 'integer',
        'used_count' => 'integer',
    ];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }
}
