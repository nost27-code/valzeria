<?php

namespace Tests\Feature;

use App\Models\NationRaidEvent;
use App\Models\User;
use App\Services\Nation\Raid\NationRaidRewardPolicy;
use App\Services\Nation\Raid\NationRaidRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class NationRaidNextScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_approves_and_schedules_the_next_cycle_once(): void
    {
        $this->travelTo('2026-09-16 12:00:00');
        $args = $this->scheduleArguments();

        $this->artisan('nation-raid:schedule-next', $args)->assertSuccessful();

        $event = NationRaidEvent::sole();
        $this->assertSame(NationRaidEvent::STATUS_SCHEDULED, $event->status);
        $this->assertSame('valgreid-2026-09-25', $event->event_key);
        $this->assertSame('2026-09-25 09:00', $event->starts_at->timezone(config('app.timezone'))->format('Y-m-d H:i'));
        $this->assertSame('2026-10-02 09:00', $event->ends_at->timezone(config('app.timezone'))->format('Y-m-d H:i'));
        $this->assertTrue($event->announced_at->eq(now()));
        $this->assertSame(30_000_000, $event->cycle_max_hp);
        $this->assertSame(3_520_000_000, $event->total_target_hp);
        $this->assertSame(30_000_000, $event->ruleset_snapshot['stages'][0]['max_hp']);
        $this->assertSame(50_000_000, $event->ruleset_snapshot['stages'][4]['max_hp']);
        $this->assertSame(100_000_000, $event->ruleset_snapshot['stages'][8]['max_hp']);
        $this->assertSame(200_000_000, $event->ruleset_snapshot['stages'][12]['max_hp']);
        $this->assertSame(500_000_000, $event->ruleset_snapshot['stages'][16]['max_hp']);
        $this->assertDatabaseCount('nation_raid_boss_cycles', 0);
        $this->assertDatabaseCount('nation_raid_nation_preparations', 0);

        $saved = $event->getRawOriginal();
        $this->travel(1)->hour();
        $this->artisan('nation-raid:schedule-next', $args)->assertSuccessful();
        $this->assertSame($saved, $event->fresh()->getRawOriginal());
        $this->assertDatabaseCount('nation_raid_events', 1);
    }

    public function test_command_rejects_unverified_or_unconfirmed_scheduling_without_writes(): void
    {
        $args = $this->scheduleArguments();
        $this->artisan('nation-raid:schedule-next', array_replace($args, [
            '--ruleset-hash' => str_repeat('0', 64),
        ]))->assertFailed();
        $this->artisan('nation-raid:schedule-next', array_replace($args, [
            '--confirm-next-cycle' => false,
        ]))->assertFailed();

        $this->assertDatabaseCount('nation_raid_events', 0);
    }

    public function test_command_rejects_a_corrupt_existing_snapshot_instead_of_treating_it_as_a_retry(): void
    {
        $args = $this->scheduleArguments();
        $this->artisan('nation-raid:schedule-next', $args)->assertSuccessful();
        $event = NationRaidEvent::sole();
        $snapshot = $event->ruleset_snapshot;
        $snapshot['stages'][0]['max_hp']++;
        $event->update(['ruleset_snapshot' => $snapshot]);

        $this->artisan('nation-raid:schedule-next', $args)->assertFailed();
        $this->assertDatabaseCount('nation_raid_events', 1);
    }

    private function scheduleArguments(): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $policy = app(NationRaidRewardPolicy::class);

        return [
            '--event-key' => 'valgreid-2026-09-25',
            '--starts-at' => '2026-09-25 09:00',
            '--admin-id' => $admin->id,
            '--approval-reference' => '2026-09-16 approved next raid dates and HP bands; total 3.52b',
            '--ruleset-hash' => app(NationRaidRules::class)->rulesetHash(),
            '--reward-policy-hash' => $policy->hash($policy->candidate()),
            '--confirm-next-cycle' => true,
        ];
    }
}
