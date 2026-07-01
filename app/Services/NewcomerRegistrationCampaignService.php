<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterConsumableItem;
use App\Models\CharacterNotification;
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

    private function itemName(): string
    {
        return (string) config('adventure_support.items.' . self::ITEM_KEY . '.name', '探索力の小瓶');
    }

    private function notificationTableExists(): bool
    {
        return Schema::hasTable('character_notifications');
    }
}
