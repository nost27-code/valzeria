<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonsterPrefix extends Model
{
    protected $guarded = [];
    protected $casts = ['eligible_biome_tags_json' => 'array', 'eligible_monster_families_json' => 'array', 'stat_modifiers_json' => 'array', 'reward_modifiers_json' => 'array', 'normal_eligible' => 'boolean', 'boss_eligible' => 'boolean', 'is_active' => 'boolean'];
}
