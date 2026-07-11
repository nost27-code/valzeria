<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlayerNamelessEquipment extends Model
{
    protected $table = 'player_nameless_equipments';

    protected $guarded = [];

    protected $casts = [
        'forge_level' => 'integer',
        'base_power' => 'integer',
        'power_per_level' => 'integer',
        'is_equipped' => 'boolean',
    ];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function displayName(): string
    {
        return trim((string) $this->custom_name) !== ''
            ? (string) $this->custom_name
            : '名もなき' . $this->equipment_type;
    }

    public function power(): int
    {
        return (int) $this->base_power + ((int) $this->forge_level * (int) $this->power_per_level);
    }
}
