<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GoldTransaction extends Model
{
    protected $fillable = [
        'character_id',
        'type',
        'amount',
        'balance_after',
        'source_type',
        'source_id',
        'note',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'integer',
        'balance_after' => 'integer',
        'metadata' => 'array',
    ];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }
}
