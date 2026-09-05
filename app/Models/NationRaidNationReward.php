<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class NationRaidNationReward extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CLAIMED = 'claimed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'nation_id_snapshot' => 'integer',
            'reward_snapshot' => 'array',
            'balance_after_snapshot' => 'array',
            'claimed_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(NationRaidEvent::class, 'event_id');
    }

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }

    public function resourceTransaction(): BelongsTo
    {
        return $this->belongsTo(NationResourceTransaction::class, 'nation_resource_transaction_id');
    }
}
