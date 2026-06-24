<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CharacterItem extends Model
{
    protected $fillable = [
        'character_id', 'item_id', 'is_equipped', 'is_stored', 'is_locked', 'enhance_level', 'equipped_slot', 'acquired_from'
    ];

    protected $casts = [
        'is_equipped' => 'boolean',
        'is_stored' => 'boolean',
        'is_locked' => 'boolean',
        'enhance_level' => 'integer',
    ];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function displayName(): string
    {
        $name = $this->item?->name ?? '不明な装備';
        $enhanceLevel = (int) ($this->enhance_level ?? 0);

        return $enhanceLevel > 0 ? "{$name} +{$enhanceLevel}" : $name;
    }
}
