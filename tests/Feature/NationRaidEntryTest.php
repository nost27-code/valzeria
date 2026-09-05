<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckCharacterSelected;
use App\Livewire\NationScreen;
use App\Models\Character;
use App\Models\NationRaidEvent;
use App\Models\User;
use App\Services\Nation\Raid\NationRaidEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class NationRaidEntryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2030, 1, 10)->setTime(9, 0));
        config([
            'features.nation_competitive_raid_enabled' => false,
            'features.nation_competitive_raid_active' => false,
            'features.nation_community_enabled' => true,
            'features.nation_development_enabled' => true,
            'features.nation_war_enabled' => false,
        ]);
        foreach (['dynamic_single', 'hit_resolution', 'damage_application', 'resources'] as $flag) {
            config()->set("battle.job_art_v2.{$flag}", true);
        }
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_disabled_entry_opens_preparing_modal_without_starting_or_spending(): void
    {
        $character = $this->character();
        $this->actingAs($character->user);

        Livewire::test(NationScreen::class)
            ->assertSeeHtml('data-nation-raid-shortcut')
            ->assertSeeHtml('data-nation-raid-entry-state="preparing"')
            ->assertSee('準備中')
            ->call('openNationCompetitiveRaid')
            ->assertNoRedirect()
            ->assertSet('pendingFeature', '国家対抗レイド')
            ->assertSee('この機能は現在準備中です。')
            ->call('closeNotImplementedModal')
            ->assertSet('pendingFeature', null);

        $this->assertSame(250, $character->fresh()->explore_stamina);
        $this->assertDatabaseCount('nation_raid_events', 0);
        $this->assertDatabaseCount('nation_raid_battle_results', 0);
        $this->assertDatabaseCount('nation_raid_personal_rewards', 0);
    }

    public function test_enabled_entry_goes_to_official_route_even_in_local_environment(): void
    {
        $character = $this->character();
        $this->actingAs($character->user);
        config()->set('features.nation_competitive_raid_enabled', true);
        $originalEnvironment = $this->app->environment();
        $this->app['env'] = 'local';
        try {
            Livewire::test(NationScreen::class)
                ->assertSeeHtml('data-nation-raid-entry-state="published"')
                ->assertDontSee('ローカル試遊')
                ->call('openNationCompetitiveRaid')
                ->assertRedirect(route('nation-raid.index'));
        } finally {
            $this->app['env'] = $originalEnvironment;
        }
        $this->assertSame(250, $character->fresh()->explore_stamina);
        $this->assertDatabaseCount('nation_raid_events', 0);
        $this->assertDatabaseCount('nation_raid_battle_results', 0);
    }

    public function test_switching_off_after_render_still_opens_modal_instead_of_redirecting(): void
    {
        $this->actingAs($this->character()->user);
        config()->set('features.nation_competitive_raid_enabled', true);
        $screen = Livewire::test(NationScreen::class);
        config()->set('features.nation_competitive_raid_enabled', false);
        $screen->call('openNationCompetitiveRaid')
            ->assertNoRedirect()
            ->assertSet('pendingFeature', '国家対抗レイド');
    }

    public function test_disabled_home_does_not_query_raid_tables_even_with_legacy_active_flag_on(): void
    {
        config()->set('features.nation_competitive_raid_active', true);
        $raidQueries = [];
        DB::listen(static function ($query) use (&$raidQueries): void {
            if (str_contains($query->sql, 'nation_raid_')) {
                $raidQueries[] = $query->sql;
            }
        });
        $html = Blade::render('<x-nation-raid-home-spotlight />');
        $this->assertStringNotContainsString('data-home-nation-raid-spotlight', $html);
        $this->assertSame([], $raidQueries);
    }

    public function test_one_publication_flag_and_approved_active_event_show_direct_home_entry(): void
    {
        $event = $this->activeEvent();
        $before = $event->getAttributes();
        $html = Blade::render('<x-nation-raid-home-spotlight />');
        $this->assertFalse(config('features.nation_competitive_raid_active'));
        $this->assertStringContainsString('data-home-nation-raid-spotlight', $html);
        $this->assertStringContainsString('開催中', $html);
        $this->assertStringContainsString('href="'.route('nation-raid.top', $event).'"', $html);
        $this->assertStringNotContainsString('nation-raid/trial', $html);
        $this->assertSame($before, $event->fresh()->getAttributes());
        $this->assertDatabaseCount('nation_raid_battle_results', 0);
    }

    #[DataProvider('unavailableStates')]
    public function test_home_does_not_advertise_an_event_that_cannot_accept_sorties(string $reason): void
    {
        $event = $this->activeEvent();
        match ($reason) {
            'off' => config()->set('features.nation_competitive_raid_enabled', false),
            'scheduled' => $event->update(['status' => NationRaidEvent::STATUS_SCHEDULED]),
            'future' => $event->update(['starts_at' => now()->addHour()]),
            'ended' => $event->update(['ends_at' => now()]),
            'paused' => $event->update(['sorties_paused_at' => now()]),
            'finalizing' => $event->update(['status' => NationRaidEvent::STATUS_FINALIZING]),
            'completed' => $event->update(['status' => NationRaidEvent::STATUS_COMPLETED]),
            'unapproved' => $event->update(['balance_approved_at' => null]),
            'changed_rules' => $event->update(['ruleset_hash' => str_repeat('0', 64)]),
            'war_enabled' => config()->set('features.nation_war_enabled', true),
        };
        $html = Blade::render('<x-nation-raid-home-spotlight />');
        $this->assertStringNotContainsString('data-home-nation-raid-spotlight', $html);
        $this->assertDatabaseCount('nation_raid_battle_results', 0);
    }

    public static function unavailableStates(): array
    {
        return array_map(static fn (string $state): array => [$state], [
            'off', 'scheduled', 'future', 'ended', 'paused', 'finalizing', 'completed',
            'unapproved', 'changed_rules', 'war_enabled',
        ]);
    }

    public function test_on_without_event_only_shows_unavailable_screen_and_does_not_create_one(): void
    {
        $character = $this->character();
        $this->withoutMiddleware(CheckCharacterSelected::class);
        config()->set('features.nation_competitive_raid_enabled', true);
        $this->actingAs($character->user)->get(route('nation-raid.index'))
            ->assertOk()->assertViewIs('nation-raid.unavailable');
        $this->assertStringNotContainsString('data-home-nation-raid-spotlight', Blade::render('<x-nation-raid-home-spotlight />'));
        $this->assertSame(250, $character->fresh()->explore_stamina);
        $this->assertDatabaseCount('nation_raid_events', 0);
    }

    private function activeEvent(): NationRaidEvent
    {
        config()->set('features.nation_competitive_raid_enabled', true);
        $service = app(NationRaidEventService::class);
        $event = $service->createDraft('entry-test', '国家対抗レイド', now());
        $service->approveBalance($event, User::factory()->create(['role' => 'admin']), 'test fixture only');
        $service->schedule($event, $event->starts_at->copy()->subHours(72));

        return $service->activate($event);
    }

    private function character(): Character
    {
        return Character::create([
            'user_id' => User::factory()->create()->id, 'name' => 'レイド入口確認者',
            'level' => 50, 'last_battle_at' => now(), 'explore_stamina' => 250,
            'explore_stamina_max' => 250, 'explore_stamina_updated_at' => now(),
        ]);
    }
}
