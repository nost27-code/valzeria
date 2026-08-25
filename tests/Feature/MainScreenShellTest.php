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

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.nation_screen_enabled', false);
        config()->set('features.nation_community_enabled', false);
        config()->set('features.nation_war_enabled', false);
    }

    public function test_shell_loads_only_the_initial_cached_panel(): void
    {
        config(['features.six_hero_ui_enabled' => true]);
        $this->actingAs(User::factory()->create());

        Livewire::test(MainScreenShell::class)
            ->assertSet('currentLocation', 'home')
            ->assertSet('initialLocation', 'home')
            ->assertSet('loadedTabLocations', ['home'])
            ->assertSeeHtml('data-main-tab-panel="home"')
            ->assertSeeHtml('data-main-tab-panel="nation"')
            ->assertDontSeeHtml('data-nation-preparation')
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

    public function test_shell_switches_to_the_nation_preparation_tab(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(MainScreenShell::class)
            ->dispatch('changeTab', newLocation: 'nation')
            ->assertSet('currentLocation', 'nation')
            ->assertSet('loadedTabLocations', ['home', 'nation'])
            ->assertSeeHtml('data-nation-preparation')
            ->assertSee('準備中');
    }

    public function test_shell_shows_the_nation_community_screen_while_nation_war_is_off(): void
    {
        config()->set('features.nation_screen_enabled', true);
        config()->set('features.nation_community_enabled', true);
        config()->set('features.nation_war_enabled', false);
        $user = User::factory()->create();
        $character = Character::query()->create([
            'user_id' => $user->id,
            'name' => '国家画面確認者',
            'icon_path' => '/images/chara/chara_001.webp',
        ]);
        session(['current_character_id' => $character->id]);
        $this->actingAs($user);

        Livewire::test(MainScreenShell::class)
            ->dispatch('changeTab', newLocation: 'nation')
            ->assertSet('currentLocation', 'nation')
            ->assertSeeHtml('wire:name="nation-screen"')
            ->assertDontSeeHtml('data-nation-preparation');
    }

    public function test_shell_does_not_mount_the_nation_screen_before_the_tab_is_selected(): void
    {
        config()->set('features.nation_screen_enabled', true);
        config()->set('features.nation_community_enabled', true);
        $user = User::factory()->create();
        $character = Character::query()->create([
            'user_id' => $user->id,
            'name' => '国家未選択確認者',
            'icon_path' => '/images/chara/chara_001.webp',
        ]);
        session(['current_character_id' => $character->id]);
        $this->actingAs($user);

        Livewire::test(MainScreenShell::class)
            ->assertSet('currentLocation', 'home')
            ->assertDontSeeHtml('wire:name="nation-screen"');
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
            ->assertSet('cachedTabLocations', ['town', 'dungeon', 'home', 'guild', 'nation', 'colosseum'])
            ->assertSeeHtml('data-main-tab-panel="colosseum"')
            ->assertSeeHtml('data-legacy-arena-home-tab')
            ->assertDontSeeHtml('data-six-hero-home-tab');
    }
}
