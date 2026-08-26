<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NationGoal extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELED = 'canceled';

    public const METRICS = [
        'material_quantity',
        'development_exp',
        'donation_points',
        'member_count',
        'facility_level',
        'manual',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'target_value' => 'integer',
            'starts_at' => 'datetime',
            'deadline_at' => 'datetime',
            'completed_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'created_by_character_id');
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }
}
