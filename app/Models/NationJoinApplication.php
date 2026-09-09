<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NationJoinApplication extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_WAITLISTED = 'waitlisted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELED = 'canceled';

    public const OPEN_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_WAITLISTED,
    ];

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'retry_after' => 'datetime',
        ];
    }

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'reviewed_by_character_id');
    }
}
