<?php

namespace Tests\Feature;

use App\Livewire\Admin\UserInvestigationManager;
use App\Models\Character;
use App\Models\PlayerLifecycleEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminUserInvestigationRecentLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_investigation_shows_recently_logged_in_users_newest_first_with_their_icons(): void
    {
        $olderUser = User::factory()->create(['name' => '先にログインした冒険者']);
        $recentUser = User::factory()->create(['name' => '最後にログインした冒険者']);
        $olderCharacter = Character::query()->create(['user_id' => $olderUser->id, 'name' => '先行キャラクター']);
        $recentCharacter = Character::query()->create(['user_id' => $recentUser->id, 'name' => '最新キャラクター', 'icon_path' => '/images/chara/chara_002.webp']);

        PlayerLifecycleEvent::query()->create([
            'user_id' => $olderUser->id,
            'event_name' => 'login',
            'event_key' => 'login:older',
            'occurred_at' => now()->subDay(),
        ]);
        PlayerLifecycleEvent::query()->create([
            'user_id' => $recentUser->id,
            'event_name' => 'login',
            'event_key' => 'login:recent',
            'occurred_at' => now(),
        ]);

        Livewire::test(UserInvestigationManager::class)
            ->assertSee('最近ログインしたユーザー')
            ->assertSeeInOrder([$recentCharacter->name, $olderCharacter->name])
            ->assertDontSee('最後にログインした冒険者')
            ->assertDontSee('先にログインした冒険者')
            ->assertSee('最新キャラクター')
            ->assertSee('chara_002.webp')
            ->assertSeeHtml(route('admin.player-controls', ['character_id' => $recentCharacter->id]))
            ->assertSeeHtml(route('admin.public-logs', ['character_id' => $recentCharacter->id]))
            ->call('investigateUser', $recentUser->id)
            ->assertSet('selectedUserId', $recentUser->id);
    }
}
