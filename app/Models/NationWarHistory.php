<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NationWarHistory extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['summary' => 'array', 'resolved_at' => 'datetime']; }
    public function declaringNation(): BelongsTo { return $this->belongsTo(Nation::class, 'declaring_nation_id'); }
    public function defendingNation(): BelongsTo { return $this->belongsTo(Nation::class, 'defending_nation_id'); }
    public function winnerNation(): BelongsTo { return $this->belongsTo(Nation::class, 'winner_nation_id'); }
}
