<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NationWarPreparationPreset extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'pool_contribution_points' => 'integer',
            'facility_upgrade_limit_points' => 'integer',
            'facility_priority' => 'array',
            'repair_reserve_warning_points' => 'integer',
            'display_order' => 'integer',
        ];
    }

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'updated_by_character_id');
    }
}
