<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class NationRaidReconstructionContribution extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'nation_id_snapshot' => 'integer',
            'character_id_snapshot' => 'integer',
            'battle_log_id' => 'integer',
            'amount' => 'integer',
            'contributed_on' => 'date',
        ];
    }

    public function invasionDamage(): BelongsTo
    {
        return $this->belongsTo(NationRaidInvasionDamage::class, 'invasion_damage_id');
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
