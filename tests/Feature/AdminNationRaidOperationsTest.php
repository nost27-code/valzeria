<?php

namespace Tests\Feature;

use App\Livewire\Admin\NationRaidOperationsManager;
use App\Models\NationRaidEvent;
use App\Models\User;
use App\Services\Nation\Raid\NationRaidEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\TestCase;

final class AdminNationRaidOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2030, 1, 1)->setTime(9, 0));
        config(['features.nation_competitive_raid_enabled' => false]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_admin_page_is_read_only_on_get_and_non_admin_cannot_access_or_call_actions(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('admin.nation-raid'))
            ->assertOk()->assertSee('国家対抗レイド 開催管理')
            ->assertSee('終了確定は最終順位・個人の報酬権利・国家報酬を保存します')->assertSee('開催の下書き');
        $this->assertDatabaseCount('nation_raid_events', 0);
        $player = User::factory()->create(['role' => 'user']);
        $this->actingAs($player)->get(route('admin.nation-raid'))->assertRedirect('/admin/login');
        Livewire::actingAs($player)->test(NationRaidOperationsManager::class)->assertForbidden();
    }

    public function test_create_is_draft_only_and_does_not_approve_schedule_or_start(): void
    {
        Log::spy();
        $admin = User::factory()->create(['role' => 'admin']);
        $screen = Livewire::actingAs($admin)->test(NationRaidOperationsManager::class)
            ->set('eventKey', 'valgreid-2030-01')->set('eventName', '国家対抗レイド')
            ->set('startsAt', '2030-01-05T09:00')->call('createDraft')->assertHasNoErrors();
        $event = NationRaidEvent::query()->sole();
        $this->assertSame('draft', $event->status);
        $this->assertSame('2030-01-05 09:00', $event->starts_at->format('Y-m-d H:i'));
        $this->assertSame(168.0, $event->starts_at->diffInHours($event->ends_at));
        $this->assertNull($event->balance_approved_at);
        $this->assertDatabaseCount('nation_raid_boss_cycles', 0);
        $this->assertDatabaseCount('nation_raid_daily_lineage_snapshots', 7);
        $screen->set('eventKey', 'valgreid-2030-01')->set('eventName', '再送')
            ->set('startsAt', '2030-01-05T09:00')->call('createDraft')->assertHasErrors('eventKey');
        $this->assertDatabaseCount('nation_raid_events', 1);
        Log::shouldHaveReceived('notice')->withArgs(fn ($message, $context) =>
            $message === 'Nation raid admin operation' && $context['action'] === 'create_draft'
            && $context['admin_user_id'] === $admin->id && $context['result'] === 'success')->once();
    }

    public function test_scheduling_requires_prior_approval_and_cannot_backdate_announcement(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $event = $this->draft(now()->addHours(71));
        $screen = Livewire::actingAs($admin)->test(NationRaidOperationsManager::class);
        $screen->call('operate', $event->id, $event->state_version, 'schedule')
            ->assertSee('バランス裁定が記録されていない');
        app(NationRaidEventService::class)->approveBalance($event, $admin, 'test only');
        $screen->call('operate', $event->id, $event->fresh()->state_version, 'schedule')
            ->assertSee('72時間前');
        $this->assertSame('draft', $event->fresh()->status);
    }

    public function test_approved_schedule_is_audited_and_stale_actions_are_rejected(): void
    {
        Log::spy();
        $admin = User::factory()->create(['role' => 'admin']);
        $event = $this->draft(now()->addHours(73));
        $event = app(NationRaidEventService::class)->approveBalance($event, $admin, 'test only');
        $version = $event->state_version;
        $screen = Livewire::actingAs($admin)->test(NationRaidOperationsManager::class);
        $screen->call('operate', $event->id, $version, 'schedule')->assertSee('開催予約を保存しました');
        $this->assertSame('scheduled', $event->fresh()->status);
        $screen->call('operate', $event->id, $version, 'cancel')->assertSee('状態が更新されています');
        $this->assertSame('scheduled', $event->fresh()->status);
        $screen->call('operate', $event->id, $event->fresh()->state_version, 'activate')->assertSee('開始時刻');
        $screen->call('operate', $event->id, $event->fresh()->state_version, 'complete')->assertSee('この操作は利用できません');
        $this->assertNull($event->fresh()->activated_at);
        $this->assertNull($event->fresh()->finalized_at);
        Log::shouldHaveReceived('notice')->withArgs(fn ($message, $context) =>
            $message === 'Nation raid admin operation' && $context['action'] === 'schedule'
            && $context['event_id'] === $event->id && $context['result'] === 'success')->once();
    }

    public function test_pause_requires_reason_and_resume_preserves_hp_and_does_not_change_flags(): void
    {
        config([
            'features.nation_competitive_raid_enabled' => true,
            'features.nation_community_enabled' => true,
            'features.nation_development_enabled' => true,
            'features.nation_war_enabled' => false,
        ]);
        foreach (['dynamic_single', 'hit_resolution', 'damage_application', 'resources'] as $flag) {
            config()->set("battle.job_art_v2.{$flag}", true);
        }
        $admin = User::factory()->create(['role' => 'admin']);
        $event = $this->draft(now());
        $service = app(NationRaidEventService::class);
        $service->approveBalance($event, $admin, 'test only');
        $service->schedule($event, now()->subHours(72));
        $event = $service->activate($event);
        $event->cycles()->sole()->update(['current_hp' => 4_900_000]);
        $screen = Livewire::actingAs($admin)->test(NationRaidOperationsManager::class);
        $screen->call('operate', $event->id, $event->state_version, 'pause')->assertHasErrors('pauseReason');
        $screen->set('pauseReason', '動作確認')->call('operate', $event->id, $event->state_version, 'pause')->assertHasNoErrors();
        $this->assertNotNull($event->fresh()->sorties_paused_at);
        config()->set('features.nation_competitive_raid_enabled', false);
        $screen->call('operate', $event->id, $event->fresh()->state_version, 'resume')->assertSee('公開gateがOFF');
        $this->assertFalse(config('features.nation_competitive_raid_enabled'));
        config()->set('features.nation_competitive_raid_enabled', true);
        $screen->call('operate', $event->id, $event->fresh()->state_version, 'resume')->assertSee('出撃受付を再開しました');
        $this->assertNull($event->fresh()->sorties_paused_at);
        $this->assertSame(4_900_000, $event->cycles()->sole()->current_hp);
        $screen->call('operate', $event->id, $event->fresh()->state_version, 'cancel')->assertSee('開始後のイベントは取消できません');
        $screen->call('operate', $event->id, $event->fresh()->state_version, 'close')->assertSee('終了時刻へ到達していません');
        $this->travelTo($event->ends_at);
        $screen->call('operate', $event->id, $event->fresh()->state_version, 'close')->assertSee('終了処理へ移行しました');
        $this->assertSame('finalizing', $event->fresh()->status);
        $this->assertNull($event->fresh()->finalized_at);
        $screen->call('retryLineages', $event->id)->assertSee('7日分確定');
        $screen->call('recoverExpiredSorties', $event->id)->assertSee('返却 0件');
        $this->assertNull($event->fresh()->finalized_at);
        $this->assertDatabaseCount('nation_raid_personal_rewards', 0);
        $this->assertDatabaseCount('nation_raid_nation_rewards', 0);
    }

    private function draft(\DateTimeInterface $startsAt): NationRaidEvent
    {
        return app(NationRaidEventService::class)->createDraft('admin-'.bin2hex(random_bytes(4)), '管理用レイド', $startsAt)->refresh();
    }
}
