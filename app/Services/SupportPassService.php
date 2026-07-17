<?php

namespace App\Services;

use App\Models\Character;
use App\Models\GameSetting;
use App\Models\PassPurchaseLog;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;

class SupportPassService
{
    public const PASS_TYPE = 'support_pass_30d';
    public const ENABLED_SETTING_KEY = 'support_pass.enabled';
    public const CARD_SKIN_DEFAULT = 'default';
    public const CARD_SKIN_SUPPORT_PASS = 'support_pass';
    public const CARD_SKIN_SUPPORT_PASS_BLUE_GOLD = 'support_pass_blue_gold';

    private ?bool $schemaReadyCache = null;

    public function storageReady(): bool
    {
        return $this->schemaReady();
    }

    public function enabled(): bool
    {
        return app(GameSettingService::class)->getBool(
            self::ENABLED_SETTING_KEY,
            (bool) config('support_pass.enabled', false)
        );
    }

    public function setEnabled(bool $enabled): void
    {
        GameSetting::updateOrCreate(
            ['setting_key' => self::ENABLED_SETTING_KEY],
            [
                'label' => '冒険者支援パス 公開状態',
                'value' => $enabled ? '1' : '0',
                'value_type' => 'boolean',
                'description' => '冒険者支援パス全体のON/OFF。OFFにすると補給商会でパス商品を表示・購入できません。',
            ]
        );

        app(GameSettingService::class)->flush();
    }

    public function isActiveForCharacter(?Character $character): bool
    {
        return $this->isActive($character?->user);
    }

    public function isActive(?User $user): bool
    {
        if (!$this->enabled()) {
            return false;
        }

        $expiresAt = $this->expiresAt($user);

        return $expiresAt !== null && $expiresAt->isFuture();
    }

    public function expiresAt(?User $user): ?CarbonInterface
    {
        if (!$user || !$this->schemaReady()) {
            return null;
        }

        return $user->support_pass_expires_at;
    }

    public function statusForCharacter(?Character $character): array
    {
        $user = $character?->user;
        $expiresAt = $this->expiresAt($user);
        $active = $this->isActive($user);

        return [
            'active' => $active,
            'expires_at' => $expiresAt,
            'remaining_days' => $active && $expiresAt
                ? max(0, intdiv(max(0, (int) now()->diffInSeconds($expiresAt, false)), 86400))
                : 0,
            'selected_card_skin' => $this->selectedCardSkin($user),
            'displayed_card_skin' => $this->displayedCardSkin($user),
            'can_extend' => $user ? $this->canExtend($user) : false,
            'max_extend_days' => $this->maxExtendDays(),
        ];
    }

    public function displayedCardSkin(?User $user): string
    {
        $selectedSkin = $this->selectedCardSkin($user);
        if ($this->isActive($user) && in_array($selectedSkin, $this->supportPassCardSkins(), true)) {
            return $selectedSkin;
        }

        return self::CARD_SKIN_DEFAULT;
    }

    public function selectedCardSkin(?User $user): string
    {
        if (!$user || !$this->schemaReady()) {
            return self::CARD_SKIN_DEFAULT;
        }

        return in_array($user->selected_card_skin, $this->selectableCardSkinValues(), true)
            ? $user->selected_card_skin
            : self::CARD_SKIN_DEFAULT;
    }

    public function cardSkinOptions(?Character $character): array
    {
        if (!$this->enabled()) {
            return [
                [
                    'value' => self::CARD_SKIN_DEFAULT,
                    'label' => '通常カード',
                    'description' => 'いつもの冒険者カードです。',
                    'selectable' => true,
                    'button_label' => '選択する',
                ],
            ];
        }

        $active = $this->isActiveForCharacter($character);

        return [
            [
                'value' => self::CARD_SKIN_DEFAULT,
                'label' => '通常カード',
                'description' => 'いつもの冒険者カードです。',
                'selectable' => true,
                'button_label' => '選択する',
            ],
            [
                'value' => self::CARD_SKIN_SUPPORT_PASS,
                'label' => '支援パスカード',
                'description' => '冒険者支援パス有効中に使える、少し特別な冒険者カードです。ステータスや戦闘力には影響しません。',
                'selectable' => $active,
                'button_label' => $active ? '選択する' : '冒険者支援パスで解放',
            ],
            [
                'value' => self::CARD_SKIN_SUPPORT_PASS_BLUE_GOLD,
                'label' => '支援パスカード 青',
                'description' => '青を基調にした、冒険者支援パス専用カードの色違いです。ステータスや戦闘力には影響しません。',
                'selectable' => $active,
                'button_label' => $active ? '選択する' : '冒険者支援パスで解放',
            ],
        ];
    }

    public function normalizeSelectableCardSkin(?Character $character, string $skin): string
    {
        if (!$this->enabled()) {
            return self::CARD_SKIN_DEFAULT;
        }

        $skin = in_array($skin, $this->selectableCardSkinValues(), true)
            ? $skin
            : self::CARD_SKIN_DEFAULT;

        if (in_array($skin, $this->supportPassCardSkins(), true) && !$this->isActiveForCharacter($character)) {
            throw new \InvalidArgumentException('支援パスカードは冒険者支援パス有効中のみ選択できます。');
        }

        return $skin;
    }

    public function canExtend(User $user): bool
    {
        if (!$this->enabled()) {
            return false;
        }

        if (!$this->schemaReady()) {
            return false;
        }

        return $this->nextExpiresAt($user)->lte($this->maxExtendUntil());
    }

    public function purchaseFor(
        Character $character,
        ?int $priceAmount = null,
        string $priceCurrency = 'kiseki'
    ): array
    {
        if (!$this->enabled()) {
            return [
                'success' => false,
                'message' => '冒険者支援パスは現在販売していません。',
            ];
        }

        if (!$this->schemaReady()) {
            return [
                'success' => false,
                'message' => '冒険者支援パスは現在準備中です。しばらくしてからお試しください。',
            ];
        }

        $user = User::query()->whereKey($character->user_id)->lockForUpdate()->firstOrFail();
        $previousExpiresAt = $this->expiresAt($user);
        $newExpiresAt = $this->nextExpiresAt($user);

        if ($newExpiresAt->gt($this->maxExtendUntil())) {
            return [
                'success' => false,
                'message' => '冒険者支援パスは最大90日先まで延長できます。現在はこれ以上延長できません。',
            ];
        }

        $user->forceFill(['support_pass_expires_at' => $newExpiresAt])->save();

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
                    . '冒険者支援パスを30日延長しました。新しい有効期限：' . $newExpiresAt->format('Y/m/d H:i'),
            ];
        }

        return [
            'success' => true,
            'message' => ($priceCurrency === 'ticket' ? '冒険者支援パス30日利用券を使用しました。' : '冒険者支援パスを購入しました。')
                . '30日間、探索力上限+250と冒険者カードの特別デザインが有効になります。',
        ];
    }

    public function staminaBonusFor(Character $character): int
    {
        return $this->isActiveForCharacter($character)
            ? max(0, (int) config('support_pass.stamina_bonus', 250))
            : 0;
    }

    public function priceKiseki(): int
    {
        return max(1, (int) config('support_pass.price_kiseki', 50));
    }

    public function durationDays(): int
    {
        return max(1, (int) config('support_pass.duration_days', 30));
    }

    public function maxExtendDays(): int
    {
        return max($this->durationDays(), (int) config('support_pass.max_extend_days', 90));
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

    /**
     * @return list<string>
     */
    private function selectableCardSkinValues(): array
    {
        return [
            self::CARD_SKIN_DEFAULT,
            ...$this->supportPassCardSkins(),
        ];
    }

    /**
     * @return list<string>
     */
    private function supportPassCardSkins(): array
    {
        return [
            self::CARD_SKIN_SUPPORT_PASS,
            self::CARD_SKIN_SUPPORT_PASS_BLUE_GOLD,
        ];
    }

    private function schemaReady(): bool
    {
        return $this->schemaReadyCache ??= Schema::hasTable('users')
            && Schema::hasColumn('users', 'support_pass_expires_at')
            && Schema::hasColumn('users', 'selected_card_skin');
    }
}
