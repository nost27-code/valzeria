<?php

namespace App\Services;

use App\Models\Character;
use App\Models\City;
use App\Models\PlayerLifecycleEvent;
use App\Models\User;
use App\Services\Admin\SecurityLoginObservationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

class PlayerLifecycleEventService
{
    public function recordRegistration(User $user): void
    {
        $this->record($user, 'registered', 'registered');
    }

    public function recordLogin(User $user, ?Character $character = null): void
    {
        $ipAddress = app()->bound('request') ? request()->ip() : null;
        app(SecurityLoginObservationService::class)->observe($user, $ipAddress);

        $date = now()->toDateString();
        $this->record($user, 'login', "login:{$date}", $character, ['date' => $date]);
    }

    public function recordCharacterCreated(Character $character): void
    {
        $this->recordForCharacter($character, 'character_created', 'character_created');
    }

    public function recordFirstBattle(Character $character, string $result): void
    {
        $this->recordForCharacter($character, 'first_battle', 'first_battle');

        if (in_array($result, ['victory', 'win'], true)) {
            $this->recordForCharacter($character, 'first_victory', 'first_victory');
        }
    }

    public function recordFirstEquipmentChange(Character $character): void
    {
        $this->recordForCharacter($character, 'first_equipment_change', 'first_equipment_change');
    }

    public function recordFirstEnhancement(Character $character): void
    {
        $this->recordForCharacter($character, 'first_enhancement', 'first_enhancement');
    }

    public function recordFirstJobChange(Character $character): void
    {
        $this->recordForCharacter($character, 'first_job_change', 'first_job_change');
    }

    public function recordFirstBossDefeat(Character $character): void
    {
        $this->recordForCharacter($character, 'first_boss_defeat', 'first_boss_defeat');
    }

    public function recordCityReached(Character $character, City $city): void
    {
        $this->recordForCharacter($character, 'city_reached', "city_reached:{$city->id}", [
            'city_id' => (int) $city->id,
            'city_name' => (string) $city->name,
            'city_order' => (int) $city->sort_order,
        ]);
    }

    public function recordPurchaseScreenViewed(Character $character): void
    {
        $date = now()->toDateString();
        $this->recordForCharacter($character, 'purchase_screen_viewed', "purchase_screen_viewed:{$date}", ['date' => $date]);
    }

    public function recordPaymentCompleted(Character $character, int $orderId): void
    {
        $this->recordForCharacter($character, 'payment_completed', "payment_completed:{$orderId}", ['order_id' => $orderId]);
    }

    private function recordForCharacter(Character $character, string $eventName, string $eventKey, array $metadata = []): void
    {
        $user = $character->relationLoaded('user') ? $character->user : User::find($character->user_id);

        if ($user) {
            $this->record($user, $eventName, $eventKey, $character, $metadata);
        }
    }

    private function record(User $user, string $eventName, string $eventKey, ?Character $character = null, array $metadata = []): void
    {
        if ((string) ($user->role ?? '') === 'admin' || ! Schema::hasTable('player_lifecycle_events')) {
            return;
        }

        try {
            PlayerLifecycleEvent::firstOrCreate(
                ['user_id' => $user->id, 'event_key' => $eventKey],
                [
                    'character_id' => $character?->id,
                    'event_name' => $eventName,
                    'metadata' => $metadata ?: null,
                    'occurred_at' => now(),
                ]
            );
        } catch (QueryException) {
            // 一意制約で重複を防ぐ。並行リクエストの衝突でもゲーム処理は継続する。
        }
    }
}
