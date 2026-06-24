<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NpcProcurementRequestTemplate extends Model
{
    protected $guarded = [];

    protected $casts = [
        'frequency_weight' => 'integer',
        'min_character_level' => 'integer',
        'max_character_level' => 'integer',
        'duration_hours' => 'integer',
        'reward_gold_on_complete' => 'integer',
        'reward_association_point_on_complete' => 'integer',
        'reward_items_json' => 'array',
        'is_active' => 'boolean',
        'display_order' => 'integer',
    ];

    public function materials()
    {
        return $this->hasMany(NpcProcurementRequestTemplateMaterial::class);
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    public function generatedRequests()
    {
        return $this->hasMany(NpcProcurementRequest::class, 'npc_procurement_request_template_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
