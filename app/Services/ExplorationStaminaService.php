<?php

namespace App\Services;

use App\Models\Character;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

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
        return $this->maxForCharacterAt($character, now());
    }

    public function baseMaxForCharacter(Character $character): int
    {
        return self::baseMaxForWins((int) ($character->wins ?? 0));
    }

    public static function baseMaxForWins(int $wins): int
    {
        $max = 250;
        $max += intdiv(min($wins, 2000), 100) * 10;                         // 〜2,000勝: 100勝ごとに+10
        $max += intdiv(min(max(0, $wins - 2000), 1000), 100) * 5;           // 2,001〜3,000勝: 100勝ごとに+5

        return min(500, $max);
    }

    /**
     * @return array{
     *     base_max: int,
     *     cap: int,
     *     at_cap: bool,
     *     wins_to_next: ?int,
     *     next_increase: int,
     *     next_base_max: int
     * }
     */
    public static function growthProgressForWins(int $wins): array
    {
        $wins = max(0, $wins);
        $baseMax = self::baseMaxForWins($wins);

        if ($baseMax >= self::DEFAULT_MAX) {
            return [
                'base_max' => self::DEFAULT_MAX,
                'cap' => self::DEFAULT_MAX,
                'at_cap' => true,
                'wins_to_next' => null,
                'next_increase' => 0,
                'next_base_max' => self::DEFAULT_MAX,
            ];
        }

        $nextMilestoneWins = min(3000, (intdiv($wins, 100) + 1) * 100);
        $nextBaseMax = self::baseMaxForWins($nextMilestoneWins);

        return [
            'base_max' => $baseMax,
            'cap' => self::DEFAULT_MAX,
            'at_cap' => false,
            'wins_to_next' => $nextMilestoneWins - $wins,
            'next_increase' => $nextBaseMax - $baseMax,
            'next_base_max' => $nextBaseMax,
        ];
    }

    public function recoverySeconds(): int
    {
        return $this->campaignActiveAt(now())
            ? max(1, (int) config('exploration_stamina_campaign.recovery_seconds', self::DEFAULT_RECOVERY_SECONDS))
            : $this->normalRecoverySeconds();
    }

    public function recoverySecondsFor(Character $character): int
    {
        return $this->recoverySecondsForAt($character, now());
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

    /** 国家戦など、探索モードにかかわらず探索力を必ず消費する用途。 */
    public function consumeRequired(Character $character, int $cost, string $errorMessage): array
    {
        if (! $this->schemaReady()) {
            return ['ok' => false, 'consumed' => 0, 'stamina' => null, 'error' => '探索力の保存領域が未準備です。'];
        }
        $cost = max(1, $cost);

        return DB::transaction(function () use ($character, $cost, $errorMessage): array {
            $locked = Character::query()->whereKey($character->id)->lockForUpdate()->firstOrFail();
            $this->recover($locked);
            $current = max(0, (int) $locked->explore_stamina);
            if ($current < $cost) {
                $character->setRawAttributes($locked->getAttributes(), true);
                return ['ok' => false, 'consumed' => 0, 'stamina' => $this->summary($locked), 'error' => $errorMessage];
            }
            $locked->update(['explore_stamina' => $current - $cost, 'explore_stamina_updated_at' => now()]);
            $character->setRawAttributes($locked->getAttributes(), true);
            return ['ok' => true, 'consumed' => $cost, 'stamina' => $this->summary($locked)];
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
        $growth = self::growthProgressForWins((int) ($character->wins ?? 0));

        if (!$this->schemaReady()) {
            return [
                'enabled' => false,
                'current' => 0,
                'max' => $this->max(),
                'base_max' => $growth['base_max'],
                'bonus_max' => 0,
                'growth' => $growth,
                'cost' => $this->cost(),
                'recovery_seconds' => $this->recoverySecondsFor($character),
                'next_recovery_seconds' => null,
            ];
        }

        $max = $this->maxForCharacter($character);
        [$current, $updatedAt] = $this->normalizedStoredStamina($character, $max);
        [$current, $updatedAt] = $this->recoveredState($character, $current, $updatedAt);
        $nextRecovery = null;

        if ($current < $max) {
            $elapsed = max(0, (int) $updatedAt->diffInSeconds(now(), false));
            $recoverySeconds = $this->recoverySecondsFor($character);
            $nextRecovery = max(1, $recoverySeconds - ($elapsed % $recoverySeconds));
        }

        return [
            'enabled' => $this->enabled(),
            'current' => $current,
            'max' => $max,
            'base_max' => $growth['base_max'],
            'bonus_max' => max(0, $max - $growth['base_max']),
            'growth' => $growth,
            'cost' => $this->cost(),
            'recovery_seconds' => $this->recoverySecondsFor($character),
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
        [$after, $updatedAt] = $this->recoveredState($character, $current, $updatedAt);

        if (!$persist) {
            $character->explore_stamina = $after;
            $character->explore_stamina_max = $max;
            $character->explore_stamina_updated_at = $updatedAt;

            return $character;
        }

        if ($character->explore_stamina === $after
            && $character->explore_stamina_max === $max
            && $character->explore_stamina_updated_at
        ) {
            return $character;
        }

        $character->explore_stamina = $after;
        $character->explore_stamina_max = $max;
        $character->explore_stamina_updated_at = $updatedAt;
        $character->save();

        return $character;
    }

    private function maxForCharacterAt(Character $character, CarbonInterface $at): int
    {
        $normalMax = $this->baseMaxForCharacter($character)
            + app(SupportPassService::class)->staminaBonusForAt($character, $at);
        $campaignBonus = $this->campaignActiveAt($at)
            ? max(0, (int) config('exploration_stamina_campaign.max_bonus', 0))
            : 0;
        $extensionBonus = app(SilverWeekExtensionPassService::class)->staminaBonusForAt($character, $at);

        return $normalMax + max($campaignBonus, $extensionBonus);
    }

    private function recoverySecondsForAt(Character $character, CarbonInterface $at): int
    {
        $seconds = $this->normalRecoverySeconds();

        if ($this->campaignActiveAt($at)) {
            $seconds = min(
                $seconds,
                max(1, (int) config('exploration_stamina_campaign.recovery_seconds', self::DEFAULT_RECOVERY_SECONDS))
            );
        }

        $extensionSeconds = app(SilverWeekExtensionPassService::class)->recoverySecondsForAt($character, $at);

        return $extensionSeconds === null ? $seconds : min($seconds, $extensionSeconds);
    }

    private function normalRecoverySeconds(): int
    {
        return max(1, app(GameSettingService::class)->getInt('exploration.stamina_recovery_seconds', self::DEFAULT_RECOVERY_SECONDS));
    }

    private function campaignWindow(): array
    {
        $timezone = (string) config('exploration_stamina_campaign.timezone', 'Asia/Tokyo');

        return [
            CarbonImmutable::parse(config('exploration_stamina_campaign.starts_at'), $timezone),
            CarbonImmutable::parse(config('exploration_stamina_campaign.ends_at'), $timezone),
        ];
    }

    private function campaignActiveAt(CarbonInterface $at): bool
    {
        [$start, $end] = $this->campaignWindow();

        return $at->greaterThanOrEqualTo($start) && $at->lessThan($end);
    }

    /** Apply each recovery interval under the cap and rate that existed at that time. */
    private function recoveredState(Character $character, int $current, CarbonInterface $updatedAt): array
    {
        $now = now();
        if ($updatedAt->greaterThan($now)) {
            return [$current, $updatedAt];
        }

        [$start, $end] = $this->campaignWindow();
        $points = [$updatedAt->copy()];
        $user = $character->user;
        $supportPassExpiresAt = app(SupportPassService::class)->expiresAt($user);
        $extensionPassStartsAt = app(SilverWeekExtensionPassService::class)->startsAt($user);
        $extensionPassExpiresAt = app(SilverWeekExtensionPassService::class)->expiresAt($user);
        foreach ([$start, $end, $supportPassExpiresAt, $extensionPassStartsAt, $extensionPassExpiresAt] as $boundary) {
            if (!$boundary) {
                continue;
            }

            if ($boundary->greaterThan($updatedAt) && $boundary->lessThanOrEqualTo($now)) {
                $points[] = $boundary;
            }
        }
        $points[] = $now;

        usort($points, fn (CarbonInterface $left, CarbonInterface $right) => $left->getTimestamp() <=> $right->getTimestamp());

        $remainder = 0;
        $anchor = $updatedAt->copy();

        for ($index = 0; $index < count($points) - 1; $index++) {
            $segmentStart = $points[$index];
            $segmentEnd = $points[$index + 1];
            $cap = $this->maxForCharacterAt($character, $segmentStart);
            $seconds = $this->recoverySecondsForAt($character, $segmentStart);

            if ($current >= $cap) {
                $remainder = 0;
                $anchor = $segmentEnd->copy();

                continue;
            }

            $elapsed = $remainder + max(0, (int) $segmentStart->diffInSeconds($segmentEnd, false));
            $recovered = min($cap - $current, intdiv($elapsed, $seconds));
            $current += $recovered;
            $remainder = $current >= $cap ? 0 : $elapsed - $recovered * $seconds;
            $anchor = $segmentEnd->copy()->subSeconds($remainder);
        }

        return [$current, $anchor];
    }

    private function normalizedStoredStamina(Character $character, int $max): array
    {
        $baseMax = $this->baseMaxForCharacter($character);
        $storedMax = $character->explore_stamina_max;
        $storedMax = $storedMax === null ? $max : (int) $storedMax;
        $current = max(0, (int) ($character->explore_stamina ?? $max));
        $updatedAt = $character->explore_stamina_updated_at ?: now();

        if ($storedMax < $baseMax) {
            $current = min($baseMax, $current + ($baseMax - $storedMax));
        }

        return [$current, $updatedAt];
    }

    private function schemaReady(): bool
    {
        $schema = app(SchemaStateService::class);

        return $this->schemaReadyCache ??= $schema->hasTable('characters')
            && $schema->hasColumns('characters', [
                'explore_stamina',
                'explore_stamina_max',
                'explore_stamina_updated_at',
            ]);
    }
}
