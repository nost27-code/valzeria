<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ArenaLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'attacker_id',
        'defender_id',
        'is_attacker_win',
        'attacker_old_rank',
        'attacker_new_rank',
        'defender_old_rank',
        'defender_new_rank',
    ];

    protected $casts = [
        'is_attacker_win' => 'boolean',
    ];

    public function attacker()
    {
        return $this->belongsTo(Character::class, 'attacker_id');
    }

    public function defender()
    {
        return $this->belongsTo(Character::class, 'defender_id');
    }
}
