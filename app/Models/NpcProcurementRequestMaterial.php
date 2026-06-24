<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NpcProcurementRequestMaterial extends Model
{
    protected $guarded = [];

    protected $casts = [
        'required_quantity' => 'integer',
        'delivered_quantity' => 'integer',
        'reward_gold_per_unit' => 'integer',
    ];

    public function request()
    {
        return $this->belongsTo(NpcProcurementRequest::class, 'npc_procurement_request_id');
    }

    public function material()
    {
        return $this->belongsTo(Material::class);
    }

    public function deliveries()
    {
        return $this->hasMany(NpcProcurementDelivery::class, 'npc_procurement_request_material_id');
    }

    public function remainingQuantity(): int
    {
        return max(0, (int) $this->required_quantity - (int) $this->delivered_quantity);
    }

    public function isFulfilled(): bool
    {
        return $this->remainingQuantity() <= 0;
    }
}
