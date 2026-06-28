<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NpcMaterialStock extends Model
{
    protected $guarded = [];

    protected $casts = [
        'npc_id' => 'integer',
        'material_id' => 'integer',
        'quantity' => 'integer',
        'last_received_at' => 'datetime',
    ];

    public function npc()
    {
        return $this->belongsTo(NpcMaster::class, 'npc_id', 'npc_id');
    }

    public function material()
    {
        return $this->belongsTo(Material::class);
    }
}
