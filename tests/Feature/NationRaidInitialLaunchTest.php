<?php

namespace Tests\Feature;

use App\Models\NationRaidEvent;
use App\Models\User;
use App\Services\Nation\Raid\NationRaidEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class NationRaidInitialLaunchTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_initial_launch_records_real_notice_time_and_is_idempotent(): void
    {
        $this->travelTo(now()->setDate(2030, 1, 10)->setTime(9, 0));
        [$event, $admin] = $this->approved('valgreid-inaugural');
        $service = app(NationRaidEventService::class);
        $scheduled = $service->scheduleInitialLaunch($event, $admin, 'User-approved initial notice exception');
        $this->assertSame('scheduled', $scheduled->status);
        $this->assertTrue($scheduled->announced_at->eq(now()));
        $this->assertTrue($scheduled->starts_at->eq(now()));
        $this->assertTrue($scheduled->ends_at->eq(now()->addHours(168)));
        $saved = $scheduled->getRawOriginal();
        $this->assertSame($saved, $service->scheduleInitialLaunch($scheduled, $admin, 'Retry')->getRawOriginal());
        $this->assertDatabaseCount('nation_raid_boss_cycles', 0);
    }

    public function test_normal_schedule_still_requires_seventy_two_hours(): void
    {
        [$event] = $this->approved('ordinary-event');
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('72');
        app(NationRaidEventService::class)->schedule($event, now());
    }

    public function test_exception_is_not_available_for_another_event_key(): void
    {
        [$event, $admin] = $this->approved('second-launch');
        $this->expectException(\DomainException::class);
        app(NationRaidEventService::class)->scheduleInitialLaunch($event, $admin, 'Approved');
    }

    public function test_previous_announced_event_prevents_using_initial_exception(): void
    {
        [$previous] = $this->approved('previous-launch');
        $previous->update(['status' => 'cancelled', 'announced_at' => now()->subDays(10)]);
        [$event, $admin] = $this->approved('valgreid-inaugural');
        $this->expectException(\DomainException::class);
        app(NationRaidEventService::class)->scheduleInitialLaunch($event, $admin, 'Approved');
    }

    public function test_non_admin_cannot_use_the_exception(): void
    {
        [$event] = $this->approved('valgreid-inaugural');
        $this->expectException(\DomainException::class);
        app(NationRaidEventService::class)->scheduleInitialLaunch($event, User::factory()->create(), 'Approved');
    }

    public function test_exception_requires_an_audit_reference(): void
    {
        [$event, $admin] = $this->approved('valgreid-inaugural');
        $this->expectException(\InvalidArgumentException::class);
        app(NationRaidEventService::class)->scheduleInitialLaunch($event, $admin, '   ');
    }

    public function test_missing_balance_approver_is_not_an_approved_event(): void
    {
        [$event] = $this->approved('ordinary-future-event', true);
        $event->update(['balance_approved_by_user_id' => null]);
        $this->expectException(\DomainException::class);
        app(NationRaidEventService::class)->schedule($event, now());
    }

    private function approved(string $key, bool $future = false): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $service = app(NationRaidEventService::class);
        $event = $service->createDraft($key, '初回開催確認', $future ? now()->addDays(4) : now());
        return [$service->approveBalance($event, $admin, 'Test fixture balance approval'), $admin];
    }

    public function test_launch_command_starts_once_and_keeps_the_original_period(): void
    {
        $args = $this->launchArguments();
        $this->artisan('nation-raid:start-initial', $args)->assertSuccessful();
        $event = NationRaidEvent::sole();
        $this->assertSame('active', $event->status);
        $this->assertTrue($event->ends_at->eq($event->starts_at->copy()->addHours(168)));
        $saved = $event->getRawOriginal();
        $this->travel(1)->hours();
        $this->artisan('nation-raid:start-initial', $args)->assertSuccessful();
        $this->assertSame($saved, $event->fresh()->getRawOriginal());
        $this->assertDatabaseCount('nation_raid_events', 1);
        $this->assertDatabaseCount('nation_raid_boss_cycles', 1);
    }

    public function test_launch_preflight_failure_rolls_back_all_draft_and_notice_writes(): void
    {
        $args = $this->launchArguments();
        config(['features.nation_competitive_raid_enabled' => false]);
        $this->artisan('nation-raid:start-initial', $args)->assertFailed();
        $this->assertDatabaseCount('nation_raid_events', 0);
        $this->assertDatabaseCount('nation_raid_daily_lineage_snapshots', 0);
        $this->assertDatabaseCount('nation_raid_boss_cycles', 0);
    }

    public function test_launch_requires_matching_hashes_and_explicit_confirmation(): void
    {
        $args = $this->launchArguments();
        $this->artisan('nation-raid:start-initial', array_replace($args, ['--ruleset-hash' => str_repeat('0', 64)]))->assertFailed();
        $this->artisan('nation-raid:start-initial', array_replace($args, ['--reward-policy-hash' => str_repeat('0', 64)]))->assertFailed();
        $this->artisan('nation-raid:start-initial', array_replace($args, ['--confirm-initial-launch' => false]))->assertFailed();
        $this->assertDatabaseCount('nation_raid_events', 0);
    }

    private function launchArguments(): array
    {
        config(['features.nation_competitive_raid_enabled' => true, 'features.nation_community_enabled' => true,
            'features.nation_development_enabled' => true, 'features.nation_war_enabled' => false]);
        foreach (['dynamic_single', 'hit_resolution', 'damage_application', 'resources'] as $flag) {
            config()->set("battle.job_art_v2.{$flag}", true);
        }
        $policy = app(\App\Services\Nation\Raid\NationRaidRewardPolicy::class);
        return ['--admin-id' => User::factory()->create(['role' => 'admin'])->id,
            '--approval-reference' => 'Initial values and notice exception approved; participation calibration remains unverified',
            '--ruleset-hash' => app(\App\Services\Nation\Raid\NationRaidRules::class)->rulesetHash(),
            '--reward-policy-hash' => $policy->hash($policy->candidate()), '--confirm-initial-launch' => true];
    }
}
