<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlayerTavernDailyNpc extends Model
{
    protected $guarded = [];

    protected $casts = [
        'tavern_date' => 'date',
    ];

    public function npc()
    {
        return $this->belongsTo(NpcMaster::class, 'npc_id', 'npc_id');
    }
}
