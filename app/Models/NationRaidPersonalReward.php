<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class NationRaidPersonalReward extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CLAIMED = 'claimed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'account_id_snapshot' => 'integer',
            'character_id_snapshot' => 'integer',
            'reward_snapshot' => 'array',
            'balance_after_snapshot' => 'array',
            'claimed_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(NationRaidEvent::class, 'event_id');
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
