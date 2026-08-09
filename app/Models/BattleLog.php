<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BattleLog extends Model
{
    use HasFactory;

    public const RESULT_WIN = 'win';

    public const RESULT_LOSE = 'lose';

    public const RESULT_EVENT = 'event';

    /** @var list<string> */
    public const WIN_RESULTS = [self::RESULT_WIN, 'victory'];

    /** @var list<string> */
    public const LOSS_RESULTS = [self::RESULT_LOSE, 'defeat', 'timeout'];

    /** @var list<string> */
    public const BATTLE_RESULTS = [self::RESULT_WIN, 'victory', self::RESULT_LOSE, 'defeat', 'timeout'];

    protected $guarded = [];

    protected $casts = [
        'job_exp_gained' => 'integer',
    ];

    public static function normalizeResult(string $result): string
    {
        $result = strtolower(trim($result));

        return match ($result) {
            'victory' => self::RESULT_WIN,
            'defeat', 'timeout' => self::RESULT_LOSE,
            default => $result,
        };
    }

    public static function isBattleResult(string $result): bool
    {
        return in_array($result, self::BATTLE_RESULTS, true);
    }

    public function scopeActualBattles(Builder $query): Builder
    {
        return $query
            ->whereIn('result', self::BATTLE_RESULTS)
            ->where(function (Builder $query): void {
                // テレメトリ追加前の実戦はNULL、新しい非戦闘イベントは0で区別する。
                $query->whereNull('turn_count')->orWhere('turn_count', '>', 0);
            });
    }

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function area()
    {
        return $this->belongsTo(Area::class);
    }

    public function enemy()
    {
        return $this->belongsTo(Enemy::class);
    }
}
