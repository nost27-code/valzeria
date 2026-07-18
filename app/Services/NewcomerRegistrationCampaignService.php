<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterConsumableItem;
use App\Models\CharacterNotification;
use App\Models\AdminItemGrantLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class NewcomerRegistrationCampaignService
{
    public const ITEM_KEY = 'explore_stamina_small_bottle';
    public const QUANTITY = 10;
    public const NOTIFICATION_TYPE = 'newcomer_stamina_bottle_gift';
    public const CAMPAIGN_START_DATE = '2026-06-30 00:00:00';
    public const BONUS_ITEM_KEY = 'explore_stamina_potion';
    public const BONUS_QUANTITY = 5;
    public const BONUS_NOTIFICATION_TYPE = 'newcomer_stamina_potion_bonus_gift';

    public function grantIfEligible(Character $character): bool
    {
        if (!$this->notificationTableExists()) {
            return false;
        }

        $character->loadMissing('user');
        if (!$this->isEligible($character)) {
            return false;
        }

        return DB::transaction(function () use ($character): bool {
            $lockedCharacter = Character::query()
                ->with('user')
                ->whereKey($character->id)
                ->lockForUpdate()
                ->first();

            if (!$lockedCharacter || !$this->isEligible($lockedCharacter) || $this->hasGranted($lockedCharacter)) {
                return false;
            }

            $itemName = $this->itemName();
            $row = CharacterConsumableItem::firstOrCreate(
                ['character_id' => $lockedCharacter->id, 'item_key' => self::ITEM_KEY],
                ['quantity' => 0]
            );
            $row->increment('quantity', self::QUANTITY);

            app(CharacterNotificationService::class)->create(
                $lockedCharacter,
                'system',
                self::NOTIFICATION_TYPE,
                '7月登録キャンペーンを受け取りました',
                "{$itemName}を" . self::QUANTITY . '個お届けしました。探索力が足りない時は、倉庫の所持品欄から使用できます。',
                '倉庫を確認する',
                route('inventory.index'),
                [
                    'item_key' => self::ITEM_KEY,
                    'quantity' => self::QUANTITY,
                    'campaign_key' => '2026_july_registration_campaign',
                    'campaign_start' => $this->campaignStart()->toDateTimeString(),
                    'granted_by' => 'auto_registration_campaign',
                ],
                10
            );

            return true;
        });
    }

    public function syncPending(): int
    {
        return $this->targetCharacters()
            ->filter(fn (Character $character): bool => !$this->hasGranted($character))
            ->sum(fn (Character $character): int => $this->grantIfEligible($character) ? 1 : 0);
    }

    public function summary(bool $syncPending = false): array
    {
        $syncedCount = $syncPending ? $this->syncPending() : 0;
        $targets = $this->targetCharacters();
        $alreadyGranted = $targets
            ->filter(fn (Character $character): bool => $this->hasGranted($character))
            ->count();

        return [
            'window_label' => $this->windowLabel(),
            'target_count' => $targets->count(),
            'already_granted_count' => $alreadyGranted,
            'pending_count' => max(0, $targets->count() - $alreadyGranted),
            'item_name' => $this->itemName(),
            'quantity' => self::QUANTITY,
            'synced_count' => $syncedCount,
        ];
    }

    public function bonusSummary(): array
    {
        $targetIds = $this->bonusTargetCharacterIds();
        $alreadyGranted = CharacterNotification::query()
            ->whereIn('character_id', $targetIds)
            ->where('type', self::BONUS_NOTIFICATION_TYPE)
            ->distinct()
            ->count('character_id');

        return [
            'target_count' => $targetIds->count(),
            'already_granted_count' => $alreadyGranted,
            'pending_count' => max(0, $targetIds->count() - $alreadyGranted),
            'item_name' => $this->itemName(self::BONUS_ITEM_KEY),
            'quantity' => self::BONUS_QUANTITY,
        ];
    }

    public function grantBonusForExistingRecipients(int $expectedTargetCount, ?int $adminUserId): int
    {
        $targetIds = $this->bonusTargetCharacterIds();
        if ($targetIds->count() !== $expectedTargetCount) {
            throw new \LogicException("追加配布対象が想定の{$expectedTargetCount}名ではありません。現在 {$targetIds->count()}名です。");
        }

        return $targetIds->sum(fn (int $characterId): int => $this->grantBonusToCharacter($characterId, $adminUserId) ? 1 : 0);
    }

    private function targetCharacters(): Collection
    {
        return Character::query()
            ->with('user')
            ->whereHas('user', fn ($query) => $query->where('created_at', '>=', $this->campaignStart()))
            ->orderBy('created_at')
            ->get();
    }

    private function isEligible(Character $character): bool
    {
        return $character->user
            && $character->user->created_at
            && $character->user->created_at->gte($this->campaignStart());
    }

    private function hasGranted(Character $character): bool
    {
        if (!$this->notificationTableExists()) {
            return false;
        }

        return CharacterNotification::query()
            ->where('character_id', $character->id)
            ->where('type', self::NOTIFICATION_TYPE)
            ->exists();
    }

    private function campaignStart(): Carbon
    {
        return Carbon::parse(self::CAMPAIGN_START_DATE, 'Asia/Tokyo');
    }

    private function windowLabel(): string
    {
        return $this->campaignStart()->format('Y/m/d') . '以降';
    }

    private function bonusTargetCharacterIds(): Collection
    {
        return CharacterNotification::query()
            ->where('type', self::NOTIFICATION_TYPE)
            ->orderBy('character_id')
            ->pluck('character_id')
            ->unique()
            ->values();
    }

    private function grantBonusToCharacter(int $characterId, ?int $adminUserId): bool
    {
        return DB::transaction(function () use ($characterId, $adminUserId): bool {
            $character = Character::query()->whereKey($characterId)->lockForUpdate()->first();
            if (!$character || CharacterNotification::query()
                ->where('character_id', $characterId)
                ->where('type', self::BONUS_NOTIFICATION_TYPE)
                ->exists()) {
                return false;
            }

            $row = CharacterConsumableItem::firstOrCreate(
                ['character_id' => $character->id, 'item_key' => self::BONUS_ITEM_KEY],
                ['quantity' => 0]
            );
            $row->increment('quantity', self::BONUS_QUANTITY);

            $itemName = $this->itemName(self::BONUS_ITEM_KEY);
            app(CharacterNotificationService::class)->create(
                $character,
                'system',
                self::BONUS_NOTIFICATION_TYPE,
                '7月登録キャンペーン 追加配布',
                "{$itemName}を" . self::BONUS_QUANTITY . '個お届けしました。探索力が足りない時は、倉庫の所持品欄から使用できます。',
                '倉庫を確認する',
                route('inventory.index'),
                [
                    'item_key' => self::BONUS_ITEM_KEY,
                    'quantity' => self::BONUS_QUANTITY,
                    'campaign_key' => '2026_july_registration_campaign_bonus',
                    'granted_by' => 'admin_campaign_bonus',
                ],
                10
            );

            AdminItemGrantLog::create([
                'character_id' => $character->id,
                'admin_user_id' => $adminUserId,
                'grant_type' => 'support_item',
                'target_type' => 'support_item',
                'target_id' => self::BONUS_ITEM_KEY,
                'target_name' => $itemName,
                'quantity' => self::BONUS_QUANTITY,
                'metadata' => ['campaign_key' => '2026_july_registration_campaign_bonus'],
            ]);

            return true;
        });
    }

    private function itemName(string $itemKey = self::ITEM_KEY): string
    {
        return (string) config('adventure_support.items.' . $itemKey . '.name', '探索力の小瓶');
    }

    private function notificationTableExists(): bool
    {
        return Schema::hasTable('character_notifications');
    }
}
