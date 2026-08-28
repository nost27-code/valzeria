<?php

namespace Tests\Feature;

use App\Livewire\MainScreen;
use App\Models\Character;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CharacterIconModalLoadingTest extends TestCase
{
    use RefreshDatabase;

    public function test_character_icon_modal_defers_offscreen_images(): void
    {
        $user = User::factory()->create();
        Character::query()->create([
            'user_id' => $user->id,
            'name' => 'アイコン表示確認',
        ]);

        $this->actingAs($user);

        Livewire::test(MainScreen::class, ['fixedLocation' => 'settings'])
            ->call('openIconModal')
            ->assertSet('isIconModalOpen', true)
            ->assertSee('loading="lazy"', false)
            ->assertSee('decoding="async"', false)
            ->assertSee('width="128"', false)
            ->assertSee('height="128"', false)
            ->assertSee('x-on:error', false)
            ->assertSee('retryCount < 2', false);
    }
}
