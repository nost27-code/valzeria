<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class NationRaidPreparationContribution extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'battle_log_id' => 'integer',
            'preparation_day' => 'integer',
            'contributed_on' => 'date',
        ];
    }

    public function preparation(): BelongsTo
    {
        return $this->belongsTo(NationRaidNationPreparation::class, 'preparation_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(NationRaidPreparationMember::class, 'preparation_member_id');
    }
}
