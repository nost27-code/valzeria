<?php

namespace Tests\Feature;

use App\Livewire\MainScreenShell;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MainScreenShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_shell_renders_the_initial_panel_and_lazy_cached_panels(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(MainScreenShell::class)
            ->assertSet('currentLocation', 'home')
            ->assertSet('initialLocation', 'home')
            ->assertSeeHtml('data-main-tab-panel="home"')
            ->assertSeeHtml('data-main-tab-panel="colosseum"');
    }

    public function test_shell_rejects_unknown_tab_names(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(MainScreenShell::class)
            ->dispatch('changeTab', newLocation: 'unknown')
            ->assertSet('currentLocation', 'home');
    }
}
