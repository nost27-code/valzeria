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

    public function getImagePathAttribute(): string
    {
        return sprintf('images/npc/npc_%03d.webp', (int) $this->npc_id);
    }
}
