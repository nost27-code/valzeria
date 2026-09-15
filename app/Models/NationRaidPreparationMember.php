<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class NationRaidPreparationMember extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'account_id_snapshot' => 'integer',
            'character_id_snapshot' => 'integer',
            'nation_id_snapshot' => 'integer',
            'contribution_count' => 'integer',
        ];
    }

    public function preparation(): BelongsTo
    {
        return $this->belongsTo(NationRaidNationPreparation::class, 'preparation_id');
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(NationRaidPreparationContribution::class, 'preparation_member_id');
    }
}
