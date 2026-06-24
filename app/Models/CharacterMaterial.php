<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CharacterMaterial extends Model
{
    protected $guarded = [];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function material()
    {
        return $this->belongsTo(Material::class);
    }
}
