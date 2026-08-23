<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NationMembership extends Model
{
    public const ROLES = ['king', 'chancellor', 'marshal', 'logistics_officer', 'citizen'];

    protected $guarded = [];
    protected function casts(): array { return ['joined_at' => 'datetime']; }
    public function nation(): BelongsTo { return $this->belongsTo(Nation::class); }
    public function character(): BelongsTo { return $this->belongsTo(Character::class); }
}
