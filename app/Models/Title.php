<?php

namespace App\Models;

use App\Support\PlayerStatLabel;
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

    public function getDescriptionAttribute(mixed $value): ?string
    {
        return $value === null ? null : PlayerStatLabel::inText((string) $value);
    }
}
