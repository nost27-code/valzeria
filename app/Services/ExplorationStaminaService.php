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
        return $this->baseMaxForCharacter($character) + app(SupportPassService::class)->staminaBonusFor($character);
    }

    public function baseMaxForCharacter(Character $character): int
    {
        return self::baseMaxForWins((int) ($character->wins ?? 0));
    }

    public static function baseMaxForWins(int $wins): int
    {
        $max = 250;
        $max += intdiv(min($wins, 2000), 10);                         // 〜2,000勝: 10勝で+1
        $max += intdiv(min(max(0, $wins - 2000), 1000), 20);           // 2,001〜3,000勝: 20勝で+1

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

        return $this->consume($character, $this->cost());
    }

    public function consume(Character $character, int $cost, string $errorMessage = '探索力が足りません。回復を待ってください。'): array
    {
        if (!$this->enabled()) {
            return ['ok' => true, 'consumed' => 0, 'stamina' => null];
        }

        $cost = max(1, $cost);

        return DB::transaction(function () use ($character, $cost, $errorMessage) {
            $locked = Character::query()->whereKey($character->id)->lockForUpdate()->firstOrFail();
            $this->recover($locked);

            $current = (int) ($locked->explore_stamina ?? 0);
            $updatedAtBeforeConsume = $locked->explore_stamina_updated_at;
            if ($current < $cost) {
                $character->setRawAttributes($locked->getAttributes(), true);

                return [
                    'ok' => false,
                    'consumed' => 0,
                    'stamina' => $this->summary($locked),
                    'error' => $errorMessage,
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

        $max = $this->maxForCharacter($character);
        [$current, $updatedAt] = $this->normalizedStoredStamina($character, $max);
        $nextRecovery = null;

        if ($current < $max) {
            $elapsed = max(0, (int) $updatedAt->diffInSeconds(now(), false));
            $recovered = intdiv($elapsed, $this->recoverySeconds());
            if ($recovered > 0) {
                $current = min($max, $current + $recovered);
                $updatedAt = $current >= $max
                    ? now()
                    : $updatedAt->copy()->addSeconds($recovered * $this->recoverySeconds());
            }
        }

        if ($current < $max) {
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
        [$current, $updatedAt] = $this->normalizedStoredStamina($character, $max);

        if ($current >= $max) {
            if ($persist && (
                $character->explore_stamina !== $current
                || $character->explore_stamina_max !== $max
                || !$character->explore_stamina_updated_at
            )) {
                $character->explore_stamina = $current;
                $character->explore_stamina_max = $max;
                $character->explore_stamina_updated_at = $updatedAt;
                $character->save();
            }

            return $character;
        }

        $elapsed = max(0, (int) $updatedAt->diffInSeconds(now(), false));
        $recovered = intdiv($elapsed, $this->recoverySeconds());
        if ($recovered <= 0) {
            if ($persist && (
                $character->explore_stamina !== $current
                || $character->explore_stamina_max !== $max
                || !$character->explore_stamina_updated_at
            )) {
                $character->explore_stamina = $current;
                $character->explore_stamina_max = $max;
                $character->explore_stamina_updated_at = $updatedAt;
                $character->save();
            }

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

    private function normalizedStoredStamina(Character $character, int $max): array
    {
        $baseMax = $this->baseMaxForCharacter($character);
        $storedMax = $character->explore_stamina_max;
        $storedMax = $storedMax === null ? $max : (int) $storedMax;
        $current = max(0, (int) ($character->explore_stamina ?? $max));
        $updatedAt = $character->explore_stamina_updated_at ?: now();

        if ($storedMax < $baseMax && $current <= $storedMax) {
            $current = $baseMax;
            $updatedAt = now();
        }

        return [$current, $updatedAt];
    }

    private function schemaReady(): bool
    {
        return $this->schemaReadyCache ??= Schema::hasTable('characters')
            && Schema::hasColumn('characters', 'explore_stamina')
            && Schema::hasColumn('characters', 'explore_stamina_max')
            && Schema::hasColumn('characters', 'explore_stamina_updated_at');
    }
}
