<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlayerExplorationSupportEffect extends Model
{
    protected $guarded = [];

    protected $casts = [
        'battles_remaining' => 'integer',
        'battles_elapsed_in_period' => 'integer',
        'proc_count' => 'integer',
        'auto_renew' => 'boolean',
        'lock_version' => 'integer',
        'last_battle_log_id' => 'integer',
    ];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }
}
