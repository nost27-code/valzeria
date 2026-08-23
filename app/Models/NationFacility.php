<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NationFacility extends Model
{
    public const TYPES = ['wall', 'magic_cannon', 'logistics', 'arsenal', 'headquarters'];
    protected $guarded = [];
    public function nation(): BelongsTo { return $this->belongsTo(Nation::class); }
}
