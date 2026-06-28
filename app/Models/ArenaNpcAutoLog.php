<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArenaNpcAutoLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_attacker_win' => 'boolean',
    ];

    public function attackerNpcRanking()
    {
        return $this->belongsTo(ArenaNpcRanking::class, 'attacker_npc_ranking_id');
    }

    public function attackerNpc()
    {
        return $this->belongsTo(NpcMaster::class, 'attacker_npc_id', 'npc_id');
    }

    public function defenderCharacter()
    {
        return $this->belongsTo(Character::class, 'defender_character_id');
    }

    public function defenderNpcRanking()
    {
        return $this->belongsTo(ArenaNpcRanking::class, 'defender_npc_ranking_id');
    }

    public function defenderNpc()
    {
        return $this->belongsTo(NpcMaster::class, 'defender_npc_id', 'npc_id');
    }
}
