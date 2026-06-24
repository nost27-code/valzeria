<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NpcMaster extends Model
{
    protected $table = 'npc_master';
    protected $primaryKey = 'npc_id';
    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
