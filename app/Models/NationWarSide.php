<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NationWarSide extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['pool_refunded' => 'boolean']; }
    public function war(): BelongsTo { return $this->belongsTo(NationWar::class, 'nation_war_id'); }
    public function nation(): BelongsTo { return $this->belongsTo(Nation::class); }
}
