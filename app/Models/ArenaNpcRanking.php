<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArenaNpcRanking extends Model
{
    protected $guarded = [];

    protected $casts = [
        'rank' => 'integer',
        'level' => 'integer',
        'wins' => 'integer',
        'losses' => 'integer',
        'is_active' => 'boolean',
    ];

    public function npc()
    {
        return $this->belongsTo(NpcMaster::class, 'npc_id', 'npc_id');
    }

    public function logs()
    {
        return $this->hasMany(ArenaNpcLog::class);
    }
}
