<?php

namespace App\Models;

use App\Enums\SixHeroRoomKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SixHeroBattleLog extends Model
{
    public const MODE_OFFICIAL = 'official';

    public const STATUS_STARTED = 'started';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    public const FAILURE_BATTLE_RUNTIME = 'battle_runtime_error';

    public const FAILURE_RESOLUTION_LOG = 'battle_resolution_log_error';

    public const FAILURE_RANKING_OUTCOME = 'ranking_outcome_error';

    protected $fillable = [
        'season_id',
        'room_key',
        'battle_mode',
        'status',
        'attacker_id',
        'defender_id',
        'attacker_rank_at_start',
        'defender_rank_at_start',
        'is_attacker_win',
        'rank_changed',
        'attacker_old_rank',
        'attacker_new_rank',
        'defender_old_rank',
        'defender_new_rank',
        'turn_count',
        'attacker_hp_ratio',
        'defender_hp_ratio',
        'daily_attempt_number',
        'started_at',
        'resolved_at',
        'completed_at',
        'failed_at',
        'failure_code',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'room_key' => SixHeroRoomKey::class,
            'attacker_rank_at_start' => 'integer',
            'defender_rank_at_start' => 'integer',
            'is_attacker_win' => 'boolean',
            'rank_changed' => 'boolean',
            'attacker_old_rank' => 'integer',
            'attacker_new_rank' => 'integer',
            'defender_old_rank' => 'integer',
            'defender_new_rank' => 'integer',
            'turn_count' => 'integer',
            'attacker_hp_ratio' => 'float',
            'defender_hp_ratio' => 'float',
            'daily_attempt_number' => 'integer',
            'started_at' => 'datetime',
            'resolved_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(SixHeroSeason::class, 'season_id');
    }

    public function attacker(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'attacker_id');
    }

    public function defender(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'defender_id');
    }
}
