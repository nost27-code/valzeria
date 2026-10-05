<?php

namespace App\Models;

use App\Services\NamelessRelicCatalog;
use Illuminate\Database\Eloquent\Model;

class PlayerRelic extends Model
{
    protected $fillable = ['character_id', 'effect_key', 'rank', 'growth_progress', 'is_locked', 'nameless_equipment_id', 'character_item_id', 'slot_number'];

    protected $casts = ['rank' => 'integer', 'growth_progress' => 'integer', 'is_locked' => 'boolean', 'slot_number' => 'integer'];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function equipment()
    {
        return $this->belongsTo(PlayerNamelessEquipment::class, 'nameless_equipment_id');
    }

    public function characterItem()
    {
        return $this->belongsTo(CharacterItem::class);
    }

    public function isAttached(): bool
    {
        return $this->nameless_equipment_id !== null || $this->character_item_id !== null;
    }

    public function displayName(): string
    {
        $catalog = app(NamelessRelicCatalog::class);

        return $catalog->definition($this->effect_key)['name'].' '.$catalog->rankLabel($this->rank);
    }

    public function effectSummary(): string
    {
        return app(NamelessRelicCatalog::class)->summary($this->effect_key, $this->rank);
    }

    public function imagePath(): ?string
    {
        return app(NamelessRelicCatalog::class)->imagePath($this->effect_key);
    }
}
