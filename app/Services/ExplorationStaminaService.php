<?php

namespace App\Services;

use App\Models\Character;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ExplorationStaminaService
{
    public const MODE_COOLDOWN = 'cooldown';
    public const MODE_STAMINA = 'stamina';

    private const DEFAULT_MAX = 500;
    private const DEFAULT_RECOVERY_SECONDS = 60;
    private const DEFAULT_COST = 1;

    private ?bool $schemaReadyCache = null;

    public function enabled(): bool
    {
        return $this->mode() === self::MODE_STAMINA && $this->schemaReady();
    }

    public function mode(): string
    {
        $mode = strtolower(app(GameSettingService::class)->getString('exploration.mode', self::MODE_COOLDOWN));

        return $mode === self::MODE_STAMINA ? self::MODE_STAMINA : self::MODE_COOLDOWN;
    }

    public function max(): int
    {
        return max(1, app(GameSettingService::class)->getInt('exploration.stamina_max', self::DEFAULT_MAX));
    }

    public function maxForCharacter(Character $character): int
    {
        $wins = (int) ($character->wins ?? 0);

        $max = 50;
        $max += intdiv(min($wins, 2000), 10);                          // 〜2,000勝: 10勝で+1
        $max += intdiv(min(max($wins - 2000, 0), 2000), 20);          // 〜4,000勝: 20勝で+1
        $max += intdiv(min(max($wins - 4000, 0), 1500), 30);          // 〜5,500勝: 30勝で+1
        $max += intdiv(min(max($wins - 5500, 0), 2000), 40);          // 〜7,500勝: 40勝で+1
        $max += intdiv(max($wins - 7500, 0), 50);                     // 7,501勝〜: 50勝で+1

        return min(500, $max);
    }

    public function recoverySeconds(): int
    {
        return max(1, app(GameSettingService::class)->getInt('exploration.stamina_recovery_seconds', self::DEFAULT_RECOVERY_SECONDS));
    }

    public function cost(): int
    {
        return max(1, app(GameSettingService::class)->getInt('exploration.stamina_cost', self::DEFAULT_COST));
    }

    public function consumeForExplore(Character $character): array
    {
        if (!$this->enabled()) {
            return ['ok' => true, 'consumed' => 0, 'stamina' => null];
        }

        return DB::transaction(function () use ($character) {
            $locked = Character::query()->whereKey($character->id)->lockForUpdate()->firstOrFail();
            $this->recover($locked);

            $cost = $this->cost();
            $current = (int) ($locked->explore_stamina ?? 0);
            $updatedAtBeforeConsume = $locked->explore_stamina_updated_at;
            if ($current < $cost) {
                $character->setRawAttributes($locked->getAttributes(), true);

                return [
                    'ok' => false,
                    'consumed' => 0,
                    'stamina' => $this->summary($locked),
                    'error' => '探索力が足りません。回復を待ってください。',
                ];
            }

            $locked->explore_stamina = $current - $cost;
            $locked->explore_stamina_updated_at = now();
            $locked->save();

            $character->setRawAttributes($locked->getAttributes(), true);

            return [
                'ok' => true,
                'consumed' => $cost,
                'stamina' => $this->summary($locked),
                'stamina_updated_at_before_consume' => $updatedAtBeforeConsume,
            ];
        });
    }

    public function refundForExplore(Character $character, int $amount, mixed $updatedAt = null): array
    {
        if ($amount <= 0 || !$this->schemaReady()) {
            return ['refunded' => 0, 'stamina' => $this->summary($character)];
        }

        return DB::transaction(function () use ($character, $amount, $updatedAt) {
            $locked = Character::query()->whereKey($character->id)->lockForUpdate()->firstOrFail();
            $this->recover($locked);

            $max = $this->maxForCharacter($locked);
            $current = max(0, (int) ($locked->explore_stamina ?? $max));
            $after = $current + $amount;
            $refunded = $after - $current;

            if ($refunded > 0) {
                $locked->explore_stamina = $after;
                $locked->explore_stamina_max = $max;
                $locked->explore_stamina_updated_at = $updatedAt ?: now();
                $locked->save();
            }

            $character->setRawAttributes($locked->getAttributes(), true);

            return ['refunded' => $refunded, 'stamina' => $this->summary($locked)];
        });
    }

    public function recoverByItem(Character $character, int $amount): array
    {
        if ($amount <= 0 || !$this->enabled()) {
            return [
                'ok' => false,
                'recovered' => 0,
                'stamina' => $this->summary($character),
                'message' => '探索力制が有効ではありません。',
            ];
        }

        $this->recover($character);

        $max = $this->maxForCharacter($character);
        $current = max(0, (int) ($character->explore_stamina ?? $max));
        $after = $current + $amount;
        $recovered = $after - $current;

        $character->explore_stamina = $after;
        $character->explore_stamina_max = $max;
        $character->explore_stamina_updated_at = $after >= $max
            ? now()
            : ($character->explore_stamina_updated_at ?: now());
        $character->save();

        return [
            'ok' => true,
            'recovered' => $recovered,
            'stamina' => $this->summary($character),
            'message' => "探索力が{$recovered}回復しました。",
        ];
    }

    public function recoverableAmount(Character $character, int $amount): int
    {
        if ($amount <= 0 || !$this->enabled()) {
            return 0;
        }

        return $amount;
    }

    public function summary(Character $character): array
    {
        if (!$this->schemaReady()) {
            return [
                'enabled' => false,
                'current' => 0,
                'max' => $this->max(),
                'cost' => $this->cost(),
                'recovery_seconds' => $this->recoverySeconds(),
                'next_recovery_seconds' => null,
            ];
        }

        $this->recover($character, persist: false);

        $max = $this->maxForCharacter($character);
        $current = max(0, (int) ($character->explore_stamina ?? $max));
        $nextRecovery = null;

        if ($current < $max) {
            $updatedAt = $character->explore_stamina_updated_at ?: now();
            $elapsed = max(0, (int) $updatedAt->diffInSeconds(now(), false));
            $nextRecovery = max(1, $this->recoverySeconds() - ($elapsed % $this->recoverySeconds()));
        }

        return [
            'enabled' => $this->enabled(),
            'current' => $current,
            'max' => $max,
            'cost' => $this->cost(),
            'recovery_seconds' => $this->recoverySeconds(),
            'next_recovery_seconds' => $nextRecovery,
        ];
    }

    public function recover(Character $character, bool $persist = true): Character
    {
        if (!$this->schemaReady()) {
            return $character;
        }

        $max = $this->maxForCharacter($character);
        $current = max(0, (int) ($character->explore_stamina ?? $max));
        $updatedAt = $character->explore_stamina_updated_at ?: now();

        if ($current >= $max) {
            if ($persist && ($character->explore_stamina_max !== $max || !$character->explore_stamina_updated_at)) {
                $character->explore_stamina_max = $max;
                $character->explore_stamina_updated_at = now();
                $character->save();
            }

            return $character;
        }

        $elapsed = max(0, (int) $updatedAt->diffInSeconds(now(), false));
        $recovered = intdiv($elapsed, $this->recoverySeconds());
        if ($recovered <= 0) {
            return $character;
        }

        $after = min($max, $current + $recovered);
        $character->explore_stamina = $after;
        $character->explore_stamina_max = $max;
        $character->explore_stamina_updated_at = $after >= $max
            ? now()
            : $updatedAt->copy()->addSeconds($recovered * $this->recoverySeconds());

        if ($persist) {
            $character->save();
        }

        return $character;
    }

    private function schemaReady(): bool
    {
        return $this->schemaReadyCache ??= Schema::hasTable('characters')
            && Schema::hasColumn('characters', 'explore_stamina')
            && Schema::hasColumn('characters', 'explore_stamina_max')
            && Schema::hasColumn('characters', 'explore_stamina_updated_at');
    }
}
