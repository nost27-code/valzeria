<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class CompetitionEventCoordinator extends Model
{
    public const GLOBAL_SLOT = 'global_competition';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'active_reference_id' => 'integer',
            'reserved_from' => 'datetime',
            'reserved_until' => 'datetime',
            'lock_version' => 'integer',
        ];
    }
}
