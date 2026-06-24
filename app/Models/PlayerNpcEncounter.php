<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlayerNpcEncounter extends Model
{
    protected $guarded = [];

    protected $casts = [
        'first_encountered_at' => 'datetime',
        'last_encountered_at' => 'datetime',
    ];

    public function npc()
    {
        return $this->belongsTo(NpcMaster::class, 'npc_id', 'npc_id');
    }
}
