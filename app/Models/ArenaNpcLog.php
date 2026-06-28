<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArenaNpcLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_attacker_win' => 'boolean',
    ];

    public function attacker()
    {
        return $this->belongsTo(Character::class, 'attacker_id');
    }

    public function npcRanking()
    {
        return $this->belongsTo(ArenaNpcRanking::class, 'arena_npc_ranking_id');
    }

    public function npc()
    {
        return $this->belongsTo(NpcMaster::class, 'npc_id', 'npc_id');
    }
}
