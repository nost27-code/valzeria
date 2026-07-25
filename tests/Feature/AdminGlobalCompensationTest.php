<?php

namespace Tests\Feature;

use App\Livewire\Admin\PlayerControlManager;
use App\Models\Character;
use App\Models\User;
use App\Services\AdminGlobalCompensationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class AdminGlobalCompensationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_grants_the_selected_stamina_item_and_custom_notification_once_to_all_players(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->createCharacter($admin, '管理者');
        $tester = User::factory()->create([
            'role' => 'user',
            'email' => 'tester_bulk_gift@valzeria.local',
        ]);
        $this->createCharacter($tester, '検証用');
        $firstPlayer = $this->createCharacter(User::factory()->create(['role' => 'user']), '冒険者A');
        $secondPlayer = $this->createCharacter(User::factory()->create(['role' => 'user']), '冒険者B');

        $service = app(AdminGlobalCompensationService::class);
        $requestUuid = (string) Str::uuid();
        $result = $service->grant(
            $requestUuid,
            'explore_stamina_potion',
            3,
            '地図探索不具合のお詫び',
            '地図探索の不具合によりご迷惑をおかけしました。探索力の薬をお受け取りください。',
            $admin->id
        );

        $this->assertSame(2, $result['target_count']);
        $this->assertSame(2, $result['granted_count']);
        $this->assertSame(0, $result['skipped_count']);

        foreach ([$firstPlayer, $secondPlayer] as $character) {
            $this->assertDatabaseHas('character_consumable_items', [
                'character_id' => $character->id,
                'item_key' => 'explore_stamina_potion',
                'quantity' => 3,
            ]);
            $this->assertDatabaseHas('character_notifications', [
                'character_id' => $character->id,
                'type' => AdminGlobalCompensationService::NOTIFICATION_TYPE,
                'title' => '地図探索不具合のお詫び',
                'body' => '地図探索の不具合によりご迷惑をおかけしました。探索力の薬をお受け取りください。',
                'action_label' => '倉庫を確認する',
            ]);
            $this->assertDatabaseHas('admin_item_grant_logs', [
                'character_id' => $character->id,
                'admin_user_id' => $admin->id,
                'grant_type' => 'global_compensation',
                'target_id' => 'explore_stamina_potion',
                'quantity' => 3,
            ]);
        }

        $this->assertDatabaseMissing('character_consumable_items', [
            'character_id' => $admin->characters()->firstOrFail()->id,
            'item_key' => 'explore_stamina_potion',
        ]);
        $this->assertDatabaseMissing('character_consumable_items', [
            'character_id' => $tester->characters()->firstOrFail()->id,
            'item_key' => 'explore_stamina_potion',
        ]);

        $duplicate = $service->grant(
            $requestUuid,
            'explore_stamina_potion',
            3,
            '地図探索不具合のお詫び',
            '地図探索の不具合によりご迷惑をおかけしました。探索力の薬をお受け取りください。',
            $admin->id
        );

        $this->assertSame(0, $duplicate['granted_count']);
        $this->assertSame(2, $duplicate['skipped_count']);
        $this->assertDatabaseCount('admin_item_grant_logs', 2);
    }

    public function test_admin_can_select_the_small_bottle_and_create_the_notification_message(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $player = $this->createCharacter(User::factory()->create([
            'role' => 'user',
            'created_at' => '2026-06-01 00:00:00',
        ]), '通知確認');
        $this->actingAs($admin);

        Livewire::test(PlayerControlManager::class)
            ->assertSee('全プレイヤーへのお詫びアイテム配布')
            ->set('globalCompensationItemKey', 'explore_stamina_small_bottle')
            ->set('globalCompensationQuantity', 2)
            ->set('globalCompensationNotificationTitle', '運営からのお詫び')
            ->set('globalCompensationNotificationBody', '探索力の小瓶をお届けしました。')
            ->call('grantGlobalCompensation')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('character_consumable_items', [
            'character_id' => $player->id,
            'item_key' => 'explore_stamina_small_bottle',
            'quantity' => 2,
        ]);
        $this->assertDatabaseHas('character_notifications', [
            'character_id' => $player->id,
            'type' => AdminGlobalCompensationService::NOTIFICATION_TYPE,
            'title' => '運営からのお詫び',
            'body' => '探索力の小瓶をお届けしました。',
        ]);
    }

    private function createCharacter(User $user, string $name): Character
    {
        return Character::create([
            'user_id' => $user->id,
            'name' => $name,
        ]);
    }
}
