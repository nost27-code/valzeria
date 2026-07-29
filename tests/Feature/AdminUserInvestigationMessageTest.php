<?php

namespace Tests\Feature;

use App\Livewire\Admin\UserInvestigationManager;
use App\Models\Character;
use App\Models\PublicLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminUserInvestigationMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_start_a_private_thread_from_user_investigation(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $targetUser = User::factory()->create();
        $character = Character::query()->create([
            'user_id' => $targetUser->id,
            'name' => '個別連絡対象',
            'explore_stamina' => 0,
        ]);

        $this->actingAs($admin);

        Livewire::test(UserInvestigationManager::class)
            ->set('userIdInput', (string) $targetUser->id)
            ->call('searchUser')
            ->assertSee('管理人個別メッセージ')
            ->assertSee('送信先：個別連絡対象')
            ->set('adminMessage', '管理人から確認したいことがあります。')
            ->call('sendAdminMessage')
            ->assertHasNoErrors()
            ->assertSet('adminMessage', '')
            ->assertSee('管理人から確認したいことがあります。');

        $this->assertDatabaseHas('public_logs', [
            'type' => 'admin_private',
            'character_id' => null,
            'receiver_id' => $character->id,
            'message' => '管理人から確認したいことがあります。',
        ]);
        $this->assertDatabaseHas('character_notifications', [
            'character_id' => $character->id,
            'type' => 'admin_private_message',
            'title' => '管理人からメッセージが届きました',
            'body' => '管理人からの個別連絡: 管理人から確認したいことがあります。',
        ]);

        PublicLog::query()->create([
            'type' => 'admin_private_reply',
            'character_id' => $character->id,
            'receiver_id' => $character->id,
            'message' => '確認しました。',
        ]);

        Livewire::test(UserInvestigationManager::class)
            ->set('userIdInput', (string) $targetUser->id)
            ->call('searchUser')
            ->assertSee('管理人から確認したいことがあります。')
            ->assertSee('確認しました。');
    }

    public function test_admin_message_rejects_a_character_outside_the_selected_user(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $targetUser = User::factory()->create();
        Character::query()->create([
            'user_id' => $targetUser->id,
            'name' => '本来の対象',
            'explore_stamina' => 0,
        ]);
        $otherCharacter = Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => '別ユーザーの冒険者',
            'explore_stamina' => 0,
        ]);

        $this->actingAs($admin);

        Livewire::test(UserInvestigationManager::class)
            ->set('userIdInput', (string) $targetUser->id)
            ->call('searchUser')
            ->set('selectedCharacterId', $otherCharacter->id)
            ->set('adminMessage', '誤送信されてはいけない内容')
            ->call('sendAdminMessage')
            ->assertHasErrors(['adminMessage']);

        $this->assertDatabaseMissing('public_logs', [
            'type' => 'admin_private',
            'receiver_id' => $otherCharacter->id,
            'message' => '誤送信されてはいけない内容',
        ]);
        $this->assertDatabaseMissing('character_notifications', [
            'character_id' => $otherCharacter->id,
            'type' => 'admin_private_message',
        ]);
    }
}
