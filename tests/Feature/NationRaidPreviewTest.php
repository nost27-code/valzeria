<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckCharacterSelected;
use App\Livewire\NationScreen;
use App\Models\Character;
use App\Models\NationRaidEvent;
use App\Models\User;
use App\Services\Nation\Raid\NationRaidEntryService;
use App\Services\Nation\Raid\NationRaidEventService;
use App\Services\Nation\Raid\NationRaidPersonalRewardCatalog;
use App\Services\Nation\Raid\NationRaidRewardPolicy;
use App\Services\Nation\Raid\NationRaidRewardScreenService;
use App\Services\Nation\Raid\NationRaidRewardService;
use App\Services\Nation\Raid\NationRaidSortieService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

final class NationRaidPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.nation_competitive_raid_enabled' => false,
            'features.nation_competitive_raid_preview_enabled' => true,
            'features.nation_community_enabled' => true, 'features.nation_development_enabled' => true,
            'features.nation_war_enabled' => false]);
        $this->withoutMiddleware(CheckCharacterSelected::class);
    }

    public function test_preview_pages_and_home_need_no_event_or_raid_queries_and_never_write(): void
    {
        $character = $this->character();
        $this->actingAs($character->user);
        $before = $character->fresh()->getAttributes();
        $unexpected = [];
        DB::listen(static function ($query) use (&$unexpected): void {
            if (str_contains($query->sql, 'nation_raid_') || preg_match('/\A\s*(insert|update|delete|replace)\b/i', $query->sql)) {
                $unexpected[] = $query->sql;
            }
        });
        $this->get(route('nation-raid.index'))->assertRedirect(route('nation-raid.preview'));
        foreach (['top', 'rewards', 'rankings'] as $page) {
            $this->get(route('nation-raid.preview', ['page' => $page]))->assertOk()
                ->assertSee('開催準備中')->assertSee('9/6 21:00開始予定')
                ->assertDontSee('method="POST"', false)->assertDontSee('name="battle_token"', false)
                ->assertDontSee('data-raid-claim-button', false)->assertDontSee('role="progressbar"', false);
        }
        $html = Blade::render('<x-nation-raid-home-spotlight />');
        $this->assertStringContainsString('data-home-nation-raid-preview', $html);
        $this->assertStringContainsString(route('nation-raid.preview'), $html);
        $this->assertStringContainsString('9/6 21:00開始予定', $html);
        $this->assertStringNotContainsString('開催中', $html);
        $this->assertNull(app(NationRaidEntryService::class)->activeEvent());
        $this->assertSame([], $unexpected);
        $this->assertSame($before, $character->fresh()->getAttributes());
        $this->assertDatabaseCount('nation_raid_events', 0);
        $this->assertDatabaseCount('nation_raid_personal_rewards', 0);
    }

    public function test_planned_rewards_reuse_the_actual_catalog_without_progress_or_entitlements(): void
    {
        $policy = app(NationRaidRewardPolicy::class)->candidate();
        $definitions = app(NationRaidPersonalRewardCatalog::class)->definitions(new NationRaidEvent(['status' => 'draft']), $policy, null, null);
        $screen = app(NationRaidRewardScreenService::class)->preview();
        $rows = collect($screen['groups'])->flatMap(fn ($group) => $group['rows'])->keyBy('key');
        $this->assertCount(16, $rows);
        $this->assertEqualsCanonicalizing(array_keys($definitions), $rows->keys()->all());
        foreach ($definitions as $key => $definition) {
            $this->assertSame($definition['payload'], $rows[$key]['payload']);
            $this->assertSame($definition['condition'], $rows[$key]['condition']);
            $this->assertSame('preview', $rows[$key]['state']);
            $this->assertNull($rows[$key]['reward_id']);
            $this->assertNull($rows[$key]['meter']);
        }
        $response = $this->actingAs($this->character()->user)->get(route('nation-raid.preview', ['page' => 'rewards']))->assertOk()
            ->assertSee('予定報酬一覧')->assertSee('有効出撃5回')->assertSee('有効出撃15回')
            ->assertSee('経験の護符')->assertSee('無償輝石 ×3')->assertSee('500万ダメージ')
            ->assertSee('称号・順位報酬')->assertDontSee('data-raid-claim-button', false);
        $this->assertSame(16, substr_count($response->getContent(), 'data-reward-state="preview"'));
    }

    public function test_preview_is_explicitly_gated_authenticated_get_only_and_allowlisted(): void
    {
        $url = route('nation-raid.preview');
        $this->get($url)->assertRedirect('/');
        $this->actingAs($this->character()->user)->get($url)->assertOk();
        $this->post($url)->assertStatus(405);
        $this->get($url.'/battle')->assertNotFound();
        config()->set('features.nation_competitive_raid_preview_enabled', false);
        $this->get($url)->assertNotFound();
        $this->get(route('nation-raid.index'))->assertNotFound();
        $this->assertStringNotContainsString('data-home-nation-raid-preview', Blade::render('<x-nation-raid-home-spotlight />'));
    }

    public function test_nation_shortcut_opens_preview_via_index_without_starting_event(): void
    {
        $this->actingAs($this->character()->user);
        Livewire::test(NationScreen::class)->assertSee('事前公開')->assertSee('9/6 21:00開始予定')
            ->call('openNationCompetitiveRaid')->assertRedirect(route('nation-raid.index'));
        $this->assertDatabaseCount('nation_raid_events', 0);
    }

    public function test_preview_does_not_authorize_official_battle_reward_or_trial_routes_or_services(): void
    {
        $character = $this->character();
        $event = app(NationRaidEventService::class)->createDraft('preview-guard', '非公開の開催予定', now());
        $this->actingAs($character->user);
        $before = $character->fresh()->getAttributes();
        $eventBefore = $event->fresh()->getAttributes();
        foreach (['show', 'top', 'rankings', 'rewards'] as $page) {
            $this->get(route('nation-raid.'.$page, $event))->assertNotFound();
        }
        $this->post(route('nation-raid.battle', $event), ['battle_token' => str_repeat('a', 64)])->assertNotFound();
        $this->post(route('nation-raid.rewards.claim', ['event' => $event, 'reward' => 1]))->assertNotFound();
        $this->get(route('nation-raid.history'))->assertNotFound();
        $this->get(route('nation-raid.trial'))->assertNotFound();
        $this->post(route('nation-raid.trial.battle'))->assertNotFound();
        foreach ([
            '現在レイドへの出撃を停止しています。' => fn () => app(NationRaidSortieService::class)->assertAdmission($event),
            '国家対抗レイドは現在準備中です。' => fn () => app(NationRaidRewardService::class)->claim($event, $character, 1),
        ] as $message => $attempt) {
            try {
                $attempt();
                $this->fail('Preview must not authorize mutations.');
            } catch (DomainException $exception) {
                $this->assertSame($message, $exception->getMessage());
            }
        }
        $this->assertSame($before, $character->fresh()->getAttributes());
        $this->assertSame($eventBefore, $event->fresh()->getAttributes());
        $this->assertDatabaseCount('nation_raid_battle_results', 0);
        $this->assertDatabaseCount('nation_raid_personal_rewards', 0);
        $this->assertDatabaseCount('nation_raid_boss_cycles', 0);
    }

    public function test_preview_does_not_override_the_existing_official_entry(): void
    {
        config()->set('features.nation_competitive_raid_enabled', true);
        $this->actingAs($this->character()->user)->get(route('nation-raid.index'))
            ->assertOk()->assertViewIs('nation-raid.unavailable');
        $this->assertStringNotContainsString('data-home-nation-raid-preview', Blade::render('<x-nation-raid-home-spotlight />'));
    }

    private function character(): Character
    {
        return Character::create(['user_id' => User::factory()->create()->id, 'name' => '事前案内の確認者',
            'level' => 50, 'last_battle_at' => now(), 'explore_stamina' => 250,
            'explore_stamina_max' => 250, 'explore_stamina_updated_at' => now()]);
    }
}
