<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NpcProcurementDelivery extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'quantity' => 'integer',
        'reward_gold' => 'integer',
        'reward_association_point' => 'integer',
        'created_at' => 'datetime',
    ];

    public function request()
    {
        return $this->belongsTo(NpcProcurementRequest::class, 'npc_procurement_request_id');
    }

    public function requestMaterial()
    {
        return $this->belongsTo(NpcProcurementRequestMaterial::class, 'npc_procurement_request_material_id');
    }

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function material()
    {
        return $this->belongsTo(Material::class);
    }
}
