<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\ValzeriaLabReplay;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class ValzeriaLabAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_feature_flag_declaration_defaults_to_disabled(): void
    {
        $source = file_get_contents(config_path('features.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString("env('VALZERIA_LAB_ENABLED', false)", $source);
    }

    public function test_disabled_lab_returns_not_found_for_an_admin(): void
    {
        config(['features.valzeria_lab_enabled' => false]);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('admin.valzeria-lab.replay'))
            ->assertNotFound();
    }

    public function test_admin_can_open_all_three_lab_pages_when_enabled(): void
    {
        config(['features.valzeria_lab_enabled' => true]);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('admin.valzeria-lab.replay'))
            ->assertOk()
            ->assertSee('Valzeria Lab / 再現')
            ->assertSee('世界グラフ')
            ->assertSee('仮想冒険者');

        $this->get(route('admin.valzeria-lab.world'))
            ->assertOk()
            ->assertSee('Valzeria Lab / 世界グラフ');

        $this->get(route('admin.valzeria-lab.adventurer'))
            ->assertOk()
            ->assertSee('Valzeria Lab / 仮想冒険者');
    }

    public function test_guest_and_non_admin_cannot_open_the_lab(): void
    {
        config(['features.valzeria_lab_enabled' => true]);

        $this->get(route('admin.valzeria-lab.replay'))->assertRedirect('/');

        $player = User::factory()->create(['role' => 'user']);
        $this->actingAs($player)
            ->get(route('admin.valzeria-lab.replay'))
            ->assertRedirect('/admin/login');

        Livewire::actingAs($player)
            ->test(ValzeriaLabReplay::class)
            ->assertForbidden();
    }

    public function test_production_admin_can_open_the_lab_when_explicitly_enabled(): void
    {
        config(['features.valzeria_lab_enabled' => true]);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->app->detectEnvironment(static fn (): string => 'production');

        $this->actingAs($admin)
            ->get(route('admin.valzeria-lab.replay'))
            ->assertOk()
            ->assertSee('Valzeria Lab / 再現');
    }

    public function test_unknown_environment_cannot_enable_the_lab(): void
    {
        config(['features.valzeria_lab_enabled' => true]);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->app->detectEnvironment(static fn (): string => 'preview');

        $this->actingAs($admin)
            ->get(route('admin.valzeria-lab.replay'))
            ->assertNotFound();
    }
}
