<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterNotification;
use App\Models\User;
use App\Services\NewcomerRegistrationCampaignService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewcomerRegistrationCampaignBonusTest extends TestCase
{
    use RefreshDatabase;

    public function test_bonus_is_granted_once_to_original_campaign_recipients(): void
    {
        $admin = User::factory()->create();
        $character = $this->createOriginalRecipient();
        $service = app(NewcomerRegistrationCampaignService::class);

        $this->assertSame(1, $service->bonusSummary()['target_count']);
        $this->assertSame(1, $service->grantBonusForExistingRecipients(1, $admin->id));
        $this->assertSame(0, $service->grantBonusForExistingRecipients(1, $admin->id));

        $this->assertDatabaseHas('character_consumable_items', [
            'character_id' => $character->id,
            'item_key' => NewcomerRegistrationCampaignService::BONUS_ITEM_KEY,
            'quantity' => NewcomerRegistrationCampaignService::BONUS_QUANTITY,
        ]);
        $this->assertDatabaseCount('character_notifications', 2);
        $this->assertDatabaseHas('character_notifications', [
            'character_id' => $character->id,
            'type' => NewcomerRegistrationCampaignService::BONUS_NOTIFICATION_TYPE,
        ]);
        $this->assertDatabaseHas('admin_item_grant_logs', [
            'character_id' => $character->id,
            'admin_user_id' => $admin->id,
            'target_id' => NewcomerRegistrationCampaignService::BONUS_ITEM_KEY,
            'quantity' => NewcomerRegistrationCampaignService::BONUS_QUANTITY,
        ]);
    }

    public function test_bonus_stops_when_target_count_is_not_the_expected_count(): void
    {
        $this->createOriginalRecipient();

        $this->expectException(\LogicException::class);
        app(NewcomerRegistrationCampaignService::class)->grantBonusForExistingRecipients(211, null);
    }

    private function createOriginalRecipient(): Character
    {
        $user = User::factory()->create();
        $character = Character::create([
            'user_id' => $user->id,
            'name' => '追加配布テスト',
        ]);
        CharacterNotification::create([
            'character_id' => $character->id,
            'category' => 'system',
            'type' => NewcomerRegistrationCampaignService::NOTIFICATION_TYPE,
            'title' => '7月登録キャンペーンを受け取りました',
            'body' => '探索力の小瓶を10個お届けしました。',
        ]);

        return $character;
    }
}
