<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MapExplorationBatch extends Model
{
    protected $guarded = [];
    protected $casts = ['result_summary_json' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'result_viewed_at' => 'datetime'];
    public function map() { return $this->belongsTo(ExplorationMap::class, 'map_id'); }
    public function registration() { return $this->belongsTo(TownMapRegistration::class, 'registration_id'); }
    public function character() { return $this->belongsTo(Character::class); }
    public function results() { return $this->hasMany(MapExplorationResult::class, 'batch_id'); }
}
