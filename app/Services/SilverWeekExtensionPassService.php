<?php

namespace App\Services;

use App\Models\Character;
use App\Models\PassPurchaseLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class SilverWeekExtensionPassService
{
    public const PASS_TYPE = 'silver_week_extension_pass_30d';
    public const TICKET_ITEM_KEY = 'silver_week_extension_pass_30d_ticket';
    public const ACTIVATION_EFFECT_TYPE = 'silver_week_extension_pass_activation';

    private ?bool $schemaReadyCache = null;

    public function storageReady(): bool
    {
        return $this->schemaReady();
    }

    public function saleStartsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse(
            config('silver_week_extension_pass.sale_starts_at'),
            (string) config('silver_week_extension_pass.timezone', 'Asia/Tokyo')
        );
    }

    public function saleAvailableAt(?CarbonInterface $at = null): bool
    {
        return ($at ?? now())->greaterThanOrEqualTo($this->saleStartsAt());
    }

    public function isActiveForCharacter(?Character $character): bool
    {
        return $this->isActive($character?->user);
    }

    public function isActive(?User $user): bool
    {
        return $this->isActiveAt($user, now());
    }

    public function isActiveAt(?User $user, CarbonInterface $at): bool
    {
        $startsAt = $this->startsAt($user);
        $expiresAt = $this->expiresAt($user);

        return $startsAt !== null
            && $startsAt->lessThanOrEqualTo($at)
            && $expiresAt !== null
            && $expiresAt->greaterThan($at);
    }

    public function startsAt(?User $user): ?CarbonInterface
    {
        if (!$user || !$this->schemaReady()) {
            return null;
        }

        return $user->silver_week_extension_pass_started_at;
    }

    public function expiresAt(?User $user): ?CarbonInterface
    {
        if (!$user || !$this->schemaReady()) {
            return null;
        }

        return $user->silver_week_extension_pass_expires_at;
    }

    public function statusForCharacter(?Character $character): array
    {
        $user = $character?->user;
        $expiresAt = $this->expiresAt($user);
        $active = $this->isActive($user);

        return [
            'active' => $active,
            'starts_at' => $this->startsAt($user),
            'expires_at' => $expiresAt,
            'remaining_days' => $active && $expiresAt
                ? max(0, intdiv(max(0, (int) now()->diffInSeconds($expiresAt, false)), 86400))
                : 0,
            'can_extend' => $user ? $this->canExtend($user) : false,
            'max_extend_days' => $this->maxExtendDays(),
            'sale_started' => $this->saleAvailableAt(),
            'sale_starts_at' => $this->saleStartsAt(),
        ];
    }

    public function canExtend(User $user): bool
    {
        if (!$this->saleAvailableAt() || !$this->schemaReady()) {
            return false;
        }

        return $this->nextExpiresAt($user)->lte($this->maxExtendUntil());
    }

    public function activateFor(
        Character $character,
        ?int $priceAmount = null,
        string $priceCurrency = 'kiseki'
    ): array {
        if (!$this->saleAvailableAt()) {
            return [
                'success' => false,
                'message' => 'シルバーウィーク仕様延長パスは2026/09/24 00:00から利用できます。',
            ];
        }

        if (!$this->schemaReady()) {
            return [
                'success' => false,
                'message' => 'シルバーウィーク仕様延長パスは現在準備中です。しばらくしてからお試しください。',
            ];
        }

        $user = User::query()->whereKey($character->user_id)->lockForUpdate()->firstOrFail();
        $previousStartsAt = $this->startsAt($user);
        $previousExpiresAt = $this->expiresAt($user);
        $newExpiresAt = $this->nextExpiresAt($user);

        if ($newExpiresAt->gt($this->maxExtendUntil())) {
            return [
                'success' => false,
                'message' => 'シルバーウィーク仕様延長パスは最大90日先まで延長できます。現在はこれ以上延長できません。',
            ];
        }

        $user->forceFill([
            'silver_week_extension_pass_started_at' => $this->isActiveAt($user, now()) && $previousStartsAt
                ? $previousStartsAt
                : now(),
            'silver_week_extension_pass_expires_at' => $newExpiresAt,
        ])->save();

        PassPurchaseLog::create([
            'user_id' => $user->id,
            'character_id' => $character->id,
            'pass_type' => self::PASS_TYPE,
            'price_currency' => $priceCurrency,
            'price_amount' => $priceAmount ?? $this->priceKiseki(),
            'purchased_at' => now(),
            'previous_expires_at' => $previousExpiresAt,
            'new_expires_at' => $newExpiresAt,
        ]);

        if ($previousExpiresAt && $previousExpiresAt->isFuture()) {
            return [
                'success' => true,
                'message' => ($priceCurrency === 'ticket' ? '利用券を使用し、' : '')
                    . 'シルバーウィーク仕様延長パスを30日延長しました。新しい有効期限：' . $newExpiresAt->format('Y/m/d H:i'),
            ];
        }

        return [
            'success' => true,
            'message' => ($priceCurrency === 'ticket'
                ? 'シルバーウィーク仕様延長パス30日利用券を使用しました。'
                : 'シルバーウィーク仕様延長パスを購入しました。')
                . '30日間、探索力が45秒で1回復し、上限が+500されます。',
        ];
    }

    public function staminaBonusForAt(Character $character, CarbonInterface $at): int
    {
        return $this->isActiveAt($character->user, $at)
            ? max(0, (int) config('silver_week_extension_pass.stamina_bonus', 500))
            : 0;
    }

    public function recoverySecondsForAt(Character $character, CarbonInterface $at): ?int
    {
        return $this->isActiveAt($character->user, $at)
            ? max(1, (int) config('silver_week_extension_pass.recovery_seconds', 45))
            : null;
    }

    public function priceKiseki(): int
    {
        return max(1, (int) config('silver_week_extension_pass.price_kiseki', 105));
    }

    public function durationDays(): int
    {
        return max(1, (int) config('silver_week_extension_pass.duration_days', 30));
    }

    public function maxExtendDays(): int
    {
        return max($this->durationDays(), (int) config('silver_week_extension_pass.max_extend_days', 90));
    }

    private function nextExpiresAt(User $user): CarbonInterface
    {
        $previousExpiresAt = $this->expiresAt($user);
        $base = $previousExpiresAt && $previousExpiresAt->isFuture() ? $previousExpiresAt : now();

        return $base->copy()->addDays($this->durationDays());
    }

    private function maxExtendUntil(): CarbonInterface
    {
        return now()->addDays($this->maxExtendDays());
    }

    private function schemaReady(): bool
    {
        $schema = app(SchemaStateService::class);

        return $this->schemaReadyCache ??= $schema->hasTable('users')
            && $schema->hasColumns('users', [
                'silver_week_extension_pass_started_at',
                'silver_week_extension_pass_expires_at',
            ])
            && $schema->hasTable('pass_purchase_logs');
    }
}
