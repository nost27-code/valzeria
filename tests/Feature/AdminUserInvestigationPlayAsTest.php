<?php

namespace Tests\Feature;

use App\Livewire\Admin\UserInvestigationManager;
use App\Models\Character;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

class AdminUserInvestigationPlayAsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_log_in_as_the_selected_character_owner(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $targetUser = User::factory()->create(['role' => 'user']);
        $character = Character::query()->create([
            'user_id' => $targetUser->id,
            'name' => 'かんりにん',
            'explore_stamina' => 0,
        ]);

        $this->actingAs($admin);

        Livewire::test(UserInvestigationManager::class)
            ->set('userIdInput', (string) $targetUser->id)
            ->call('searchUser')
            ->assertSee('かんりにんとしてログイン')
            ->call('playAsSelectedUser')
            ->assertRedirect(route('home'));

        $this->assertSame($targetUser->id, Auth::id());
        $this->assertSame($character->id, session('current_character_id'));
    }

    public function test_non_admin_cannot_call_play_as_directly(): void
    {
        $currentUser = User::factory()->create(['role' => 'user']);
        $targetUser = User::factory()->create(['role' => 'user']);
        $character = Character::query()->create([
            'user_id' => $targetUser->id,
            'name' => '切替対象',
            'explore_stamina' => 0,
        ]);

        $this->actingAs($currentUser);

        Livewire::test(UserInvestigationManager::class)
            ->set('selectedUserId', $targetUser->id)
            ->set('selectedCharacterId', $character->id)
            ->call('playAsSelectedUser')
            ->assertForbidden();

        $this->assertSame($currentUser->id, Auth::id());
    }

    public function test_admin_cannot_play_as_another_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $targetAdmin = User::factory()->create(['role' => 'admin']);
        $character = Character::query()->create([
            'user_id' => $targetAdmin->id,
            'name' => '別の管理者',
            'explore_stamina' => 0,
        ]);

        $this->actingAs($admin);

        Livewire::test(UserInvestigationManager::class)
            ->set('selectedUserId', $targetAdmin->id)
            ->set('selectedCharacterId', $character->id)
            ->call('playAsSelectedUser')
            ->assertNoRedirect();

        $this->assertSame($admin->id, Auth::id());
    }
}
