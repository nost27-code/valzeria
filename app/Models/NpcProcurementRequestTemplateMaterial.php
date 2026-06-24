<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NpcProcurementRequestTemplateMaterial extends Model
{
    protected $guarded = [];

    protected $casts = [
        'required_quantity' => 'integer',
        'reward_gold_per_unit' => 'integer',
    ];

    public function template()
    {
        return $this->belongsTo(NpcProcurementRequestTemplate::class, 'npc_procurement_request_template_id');
    }

    public function material()
    {
        return $this->belongsTo(Material::class);
    }
}
