<?php

namespace Tests\Feature;

use App\Livewire\MainScreenShell;
use App\Models\Character;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MainScreenShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_shell_loads_only_the_initial_cached_panel(): void
    {
        config(['features.six_hero_ui_enabled' => true]);
        $this->actingAs(User::factory()->create());

        Livewire::test(MainScreenShell::class)
            ->assertSet('currentLocation', 'home')
            ->assertSet('initialLocation', 'home')
            ->assertSet('loadedTabLocations', ['home'])
            ->assertSeeHtml('data-main-tab-panel="home"')
            ->assertSeeHtml('data-main-tab-panel="colosseum"');
    }

    public function test_shell_loads_a_cached_panel_on_first_selection_and_keeps_it_loaded(): void
    {
        config(['features.six_hero_ui_enabled' => true]);
        $this->actingAs(User::factory()->create());

        Livewire::test(MainScreenShell::class)
            ->dispatch('changeTab', newLocation: 'town')
            ->assertSet('currentLocation', 'town')
            ->assertSet('loadedTabLocations', ['home', 'town'])
            ->dispatch('changeTab', newLocation: 'home')
            ->assertSet('currentLocation', 'home')
            ->assertSet('loadedTabLocations', ['home', 'town']);
    }

    public function test_shell_rejects_unknown_tab_names(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(MainScreenShell::class)
            ->dispatch('changeTab', newLocation: 'unknown')
            ->assertSet('currentLocation', 'home');
    }

    public function test_shell_keeps_the_legacy_colosseum_tab_when_six_heroes_is_off(): void
    {
        config(['features.six_hero_ui_enabled' => false]);
        $user = User::factory()->create();
        $character = Character::query()->create([
            'user_id' => $user->id,
            'name' => '従来闘技場確認者',
            'icon_path' => '/images/chara/chara_001.webp',
        ]);
        session(['current_location' => 'colosseum']);
        session(['current_character_id' => $character->id]);
        $this->actingAs($user);

        Livewire::test(MainScreenShell::class)
            ->assertSet('currentLocation', 'colosseum')
            ->assertSet('cachedTabLocations', ['town', 'dungeon', 'home', 'guild', 'colosseum'])
            ->assertSeeHtml('data-main-tab-panel="colosseum"')
            ->assertSeeHtml('data-legacy-arena-home-tab')
            ->assertDontSeeHtml('data-six-hero-home-tab');
    }
}
