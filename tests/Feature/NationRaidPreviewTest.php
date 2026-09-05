<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckCharacterSelected;
use App\Livewire\NationScreen;
use App\Models\Character;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

final class NationRaidPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_are_available_without_any_raid_table_or_engine_and_do_not_write(): void
    {
        $this->withoutMiddleware(CheckCharacterSelected::class);
        $character = $this->character();
        $this->actingAs($character->user);
        config(['features.nation_competitive_raid_preview_enabled' => true]);
        $this->assertFalse(Schema::hasTable('nation_raid_events'));
        $this->assertFalse(class_exists('App\\Services\\Nation\\Raid\\NationRaidBattleEngine'));
        $unexpected = [];
        DB::listen(static function ($query) use (&$unexpected): void {
            if (str_contains($query->sql, 'nation_raid_') || preg_match('/\A\s*(insert|update|delete|replace)\b/i', $query->sql)) {
                $unexpected[] = $query->sql;
            }
        });
        foreach (['/nation-raid', '/nation-raid/preview', '/nation-raid/preview/top', '/nation-raid/preview/rewards', '/nation-raid/preview/rankings'] as $url) {
            $this->get($url)->assertOk()->assertSee('開催準備中')->assertSee('開催日未定')
                ->assertDontSee('method="POST"', false)->assertDontSee('data-raid-claim-button', false)
                ->assertDontSee('name="battle_token"', false)->assertDontSee('role="progressbar"', false);
        }
        $this->assertSame([], $unexpected);
    }

    public function test_off_gate_hides_all_entries_and_closes_all_pages(): void
    {
        $this->withoutMiddleware(CheckCharacterSelected::class);
        $this->actingAs($this->character()->user);
        $this->assertFalse(config('features.nation_competitive_raid_preview_enabled'));
        foreach (['/nation-raid', '/nation-raid/preview', '/nation-raid/preview/rewards', '/nation-raid/preview/rankings'] as $url) {
            $this->get($url)->assertNotFound();
        }
        $this->assertStringNotContainsString('data-nation-raid-preview-entry', Blade::render('<x-nation-raid-preview-card />'));
    }

    public function test_authentication_character_selection_and_page_allowlist_are_preserved(): void
    {
        config(['features.nation_competitive_raid_preview_enabled' => true]);
        $this->get('/nation-raid/preview')->assertRedirect();
        $this->actingAs(User::factory()->create())->get('/nation-raid/preview')->assertRedirect(route('character.select'));
        $this->withoutMiddleware(CheckCharacterSelected::class);
        $this->actingAs($this->character()->user);
        $this->get('/nation-raid/preview/battle')->assertNotFound();
        $this->get('/nation-raid/preview/invalid')->assertNotFound();
        $this->post('/nation-raid/preview')->assertStatus(405);
        $this->post('/nation-raid/preview/rewards')->assertStatus(405);
    }

    public function test_no_official_battle_claim_trial_or_event_write_route_is_released(): void
    {
        $this->withoutMiddleware(CheckCharacterSelected::class);
        $this->actingAs($this->character()->user);
        config(['features.nation_competitive_raid_preview_enabled' => true,
            'features.nation_competitive_raid_enabled' => true]); // Even a mistaken future flag cannot release missing code.
        foreach (['/nation-raid/events/1/battle', '/nation-raid/events/1/sorties', '/nation-raid/events/1/rewards/1/claim', '/nation-raid/trial/battle', '/nation-raid/trial'] as $url) {
            $this->post($url)->assertNotFound();
        }
        $this->get('/nation-raid/trial')->assertNotFound();
        $this->assertSame(['nation-raid.index', 'nation-raid.preview'], array_values(array_map(
            static fn ($route) => $route->getName(), array_filter(Route::getRoutes()->getRoutes(),
                static fn ($route) => str_starts_with($route->uri(), 'nation-raid')))));
    }

    public function test_approved_display_snapshot_has_sixteen_goals_and_existing_images(): void
    {
        $this->withoutMiddleware(CheckCharacterSelected::class);
        $this->actingAs($this->character()->user);
        config(['features.nation_competitive_raid_preview_enabled' => true]);
        $preview = config('nation_raid_preview');
        $this->assertSame('b0a4b87e859f5737da207f8630f0712f73cf2f4a6ff8802a4abf8b02d1144e4b', $preview['source_policy_hash']);
        $this->assertSame(5, $preview['participation_minimum_sorties']);
        $this->assertSame(15, $preview['minimum_sorties']);
        $rows = collect($preview['groups'])->flatMap(fn ($group) => $group['rows']);
        $this->assertCount(16, $rows);
        $this->assertSame(['milestone_10000', 'milestone_50000', 'milestone_100000', 'milestone_250000', 'milestone_500000', 'milestone_750000', 'milestone_1000000', 'milestone_2000000', 'milestone_5000000'], array_column($preview['groups']['damage']['rows'], 'key'));
        $this->assertFileExists(public_path($preview['boss_image']));
        $response = $this->get('/nation-raid/preview/rewards')->assertOk();
        foreach ($rows as $row) {
            $response->assertSee($row['display_label'])->assertSee($row['condition']);
            foreach ($row['items'] as $item) {
                $response->assertSee($item['label']);
                $this->assertFileExists(public_path($item['icon']));
            }
        }
        $this->assertSame(16, substr_count($response->getContent(), 'data-raid-preview-reward='));
        $response->assertDontSee('入手')->assertDontSee('<select', false);
    }

    public function test_nation_entry_is_visible_to_unaffiliated_players_without_changing_war_gate(): void
    {
        $character = $this->character();
        config(['features.nation_competitive_raid_preview_enabled' => true,
            'features.nation_community_enabled' => true, 'features.nation_war_enabled' => false]);
        Livewire::actingAs($character->user)->test(NationScreen::class)->assertSee('国家対抗レイド')
            ->assertSeeHtml('data-nation-raid-preview-entry')->assertSee('事前公開・開催準備中');
        $this->assertStringContainsString(route('nation-raid.preview'), Blade::render('<x-nation-raid-preview-card />'));
        $this->assertFalse(config('features.nation_war_enabled'));
    }

    public function test_member_top_and_all_menu_both_link_to_the_preview(): void
    {
        $character = $this->character();
        config(['features.nation_competitive_raid_preview_enabled' => true,
            'features.nation_community_enabled' => true, 'features.nation_war_enabled' => false]);
        app(\App\Services\Nation\NationService::class)->create($character, '事前公開確認国');
        $screen = Livewire::actingAs($character->user)->test(NationScreen::class);
        $this->assertSame(1, substr_count($screen->html(), 'data-nation-raid-preview-entry'));
        $screen->call('openNationMenuModal')->assertSee('国家対抗レイド');
        $this->assertSame(2, substr_count($screen->html(), 'data-nation-raid-preview-entry'));
        $screen->assertSee('宣戦布告'); // Preview publication does not change existing war gates/menu.
    }

    private function character(): Character
    {
        return Character::create(['user_id' => User::factory()->create()->id, 'name' => '事前案内の確認者',
            'level' => 50, 'last_battle_at' => now(), 'explore_stamina' => 250,
            'explore_stamina_max' => 250, 'explore_stamina_updated_at' => now()]);
    }
}
