<?php

namespace App\Models;

use App\Enums\SixHeroRoomKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SixHeroRanking extends Model
{
    protected $fillable = [
        'season_id',
        'room_key',
        'character_id',
        'rank',
        'official_attack_wins',
        'official_attack_losses',
        'defense_wins',
        'defense_losses',
        'registered_at',
        'first_place_since',
    ];

    protected function casts(): array
    {
        return [
            'room_key' => SixHeroRoomKey::class,
            'rank' => 'integer',
            'official_attack_wins' => 'integer',
            'official_attack_losses' => 'integer',
            'defense_wins' => 'integer',
            'defense_losses' => 'integer',
            'registered_at' => 'datetime',
            'first_place_since' => 'datetime',
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
