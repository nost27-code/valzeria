<?php

namespace App\Models;

use App\Enums\SixHeroRoomKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SixHeroChampion extends Model
{
    public const VACANCY_INSUFFICIENT_PARTICIPANTS = 'insufficient_participants';

    public const VACANCY_INSUFFICIENT_ACTIVITY = 'insufficient_activity';

    protected $fillable = [
        'season_id',
        'room_key',
        'character_id',
        'character_id_snapshot',
        'character_name_snapshot',
        'is_vacant',
        'vacancy_reason',
        'registered_count',
        'official_battle_count',
        'official_attack_wins',
        'official_attack_losses',
        'defense_wins',
        'defense_losses',
    ];

    protected static function booted(): void
    {
        static::updating(function (SixHeroChampion $champion): void {
            if ($champion->isDirty('character_id_snapshot')) {
                throw new LogicException(
                    'Six Heroes Champion identity snapshots are immutable.',
                );
            }
        });
    }

    protected function casts(): array
    {
        return [
            'room_key' => SixHeroRoomKey::class,
            'character_id_snapshot' => 'integer',
            'is_vacant' => 'boolean',
            'registered_count' => 'integer',
            'official_battle_count' => 'integer',
            'official_attack_wins' => 'integer',
            'official_attack_losses' => 'integer',
            'defense_wins' => 'integer',
            'defense_losses' => 'integer',
        ];
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(SixHeroSeason::class, 'season_id');
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'character_id');
    }
}
