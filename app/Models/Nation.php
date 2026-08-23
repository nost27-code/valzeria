<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Nation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['founded_at' => 'datetime', 'loss_protected_until' => 'datetime'];
    }

    public function memberships(): HasMany { return $this->hasMany(NationMembership::class); }
    public function facilities(): HasMany { return $this->hasMany(NationFacility::class); }
    public function resourceTransactions(): HasMany { return $this->hasMany(NationResourceTransaction::class); }
}
