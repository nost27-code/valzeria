<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExplorationLootLog extends Model
{
    protected $fillable = [
        'character_id',
        'area_id',
        'character_item_id',
        'material_id',
        'quantity',
        'penalized',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'penalized' => 'boolean',
    ];

    public function material()
    {
        return $this->belongsTo(Material::class);
    }

    public function characterItem()
    {
        return $this->belongsTo(CharacterItem::class);
    }
}
