<?php

namespace Tests\Feature;

use App\Livewire\Admin\PlayerControlManager;
use App\Livewire\Admin\PublicLogManager;
use App\Models\Character;
use App\Models\PublicLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminUserInvestigationLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_player_control_link_selects_the_requested_character(): void
    {
        $character = Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => '調整対象冒険者',
        ]);

        Livewire::withQueryParams(['character_id' => (string) $character->id])
            ->test(PlayerControlManager::class)
            ->assertSet('selectedCharacterId', $character->id);
    }

    public function test_public_log_link_filters_sender_and_receiver_logs_for_the_requested_character(): void
    {
        $target = Character::query()->create(['user_id' => User::factory()->create()->id, 'name' => '調査対象']);
        $other = Character::query()->create(['user_id' => User::factory()->create()->id, 'name' => '別の冒険者']);

        PublicLog::query()->create(['type' => 'chat', 'character_id' => $target->id, 'message' => '対象が送信したログ']);
        PublicLog::query()->create(['type' => 'private', 'character_id' => $other->id, 'receiver_id' => $target->id, 'message' => '対象が受信したログ']);
        PublicLog::query()->create(['type' => 'chat', 'character_id' => $other->id, 'message' => '別の人のログ']);

        Livewire::withQueryParams(['character_id' => (string) $target->id])
            ->test(PublicLogManager::class)
            ->assertSet('characterId', $target->id)
            ->assertSee('調査対象')
            ->assertSee('対象が送信したログ')
            ->assertSee('対象が受信したログ')
            ->assertDontSee('別の人のログ');
    }
}
