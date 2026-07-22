<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExplorationMap extends Model
{
    protected $guarded = [];

    protected $casts = [
        'name_parts_json' => 'array', 'normal_monster_variants_json' => 'array', 'boss_monster_variants_json' => 'array',
        'environment_effects_json' => 'array', 'reward_modifiers_json' => 'array', 'generation_payload_json' => 'array',
    ];

    public function owner() { return $this->belongsTo(Character::class, 'owner_character_id'); }
    public function sourceArea() { return $this->belongsTo(Area::class, 'source_area_id'); }
    public function registration() { return $this->hasOne(TownMapRegistration::class, 'map_id'); }
}
