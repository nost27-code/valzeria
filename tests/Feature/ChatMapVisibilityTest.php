<?php

namespace Tests\Feature;

use App\Livewire\ChatLog;
use App\Models\Character;
use App\Models\PublicLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ChatMapVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_map_filter_is_independent_persists_and_system_tab_remains_available(): void
    {
        $character = Character::create(['user_id' => User::factory()->create()->id, 'name' => '地図通知試験']);
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id]);
        PublicLog::create(['type' => 'system_map_published', 'message' => '地図公開通知試験', 'character_id' => $character->id]);
        PublicLog::create(['type' => 'system', 'message' => '通常システム試験', 'character_id' => $character->id]);
        Livewire::test(ChatLog::class)
            ->assertSee('地図公開通知試験')->assertSee('通常システム試験')
            ->call('setAllTabVisibility', 'map', false)
            ->assertDontSee('地図公開通知試験')->assertSee('通常システム試験');
        $this->assertFalse($character->fresh()->chat_all_tab_visibility['map']);
        Livewire::test(ChatLog::class)->assertDontSee('地図公開通知試験')
            ->call('setTab', 'system')->assertSee('地図公開通知試験');
    }
}
