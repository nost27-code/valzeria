<?php

namespace Tests\Feature;

use App\Models\NationRaidEvent;
use App\Models\User;
use App\Services\Nation\Raid\NationRaidAstragiaAdoptionService;
use App\Services\Nation\Raid\NationRaidEventService;
use App\Services\Nation\Raid\NationRaidRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class NationRaidAstragiaAdoptionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_command_adopts_astragia_without_changing_schedule_hp_or_rewards_and_is_idempotent(): void
    {
        $this->travelTo('2026-09-16 12:00:00');
        [$event, $admin] = $this->scheduledValgreidEvent();
        $preservedKeys = [
            'event_key', 'status', 'announced_at', 'starts_at', 'ends_at',
            'stage_count', 'cycle_max_hp', 'total_target_hp',
            'reward_policy_snapshot', 'reward_policy_hash',
        ];
        $preserved = array_intersect_key($event->getRawOriginal(), array_flip($preservedKeys));
        $arguments = $this->commandArguments($event, $admin);

        $this->assertSame(0, Artisan::call('nation-raid:adopt-astragia', $arguments), Artisan::output());
        $event->refresh();

        $this->assertSame($preserved, array_intersect_key($event->getRawOriginal(), array_flip($preservedKeys)));
        $this->assertSame(NationRaidRules::EVENT_NAME, $event->name);
        $this->assertSame(NationRaidRules::BOSS_NAME, $event->boss_name);
        $this->assertSame(NationRaidRules::RULESET_VERSION, $event->ruleset_version);
        $this->assertSame('machine', $event->ruleset_snapshot['fixed']['boss_species_key']);
        $this->assertSame('images/raid/astragia_form_01.webp', $event->ruleset_snapshot['forms']['sealed_scale']['image_path']);
        $this->assertSame(app(NationRaidRules::class)->rulesetHash(), $event->ruleset_hash);
        $this->assertDatabaseCount('nation_raid_boss_cycles', 0);
        $this->assertDatabaseCount('nation_raid_participations', 0);
        $this->assertDatabaseCount('nation_raid_battle_results', 0);

        $this->assertSame(0, Artisan::call('nation-raid:adopt-astragia', $arguments), Artisan::output());
        $this->assertDatabaseCount('nation_raid_events', 1);
    }

    public function test_command_rejects_adoption_after_logistics_preparation_begins(): void
    {
        $this->travelTo('2026-09-16 12:00:00');
        [$event, $admin] = $this->scheduledValgreidEvent();
        $arguments = $this->commandArguments($event, $admin);

        $this->travelTo('2026-09-22 09:00:00');

        $this->assertSame(1, Artisan::call('nation-raid:adopt-astragia', $arguments), Artisan::output());
        $event->refresh();
        $this->assertSame('十系喰らいの黒天竜 ヴァルグレイド', $event->boss_name);
        $this->assertSame(app(NationRaidRules::class)->previousNextCycleRulesetHash(), $event->ruleset_hash);
    }

    /** @return array{NationRaidEvent,User} */
    private function scheduledValgreidEvent(): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $events = app(NationRaidEventService::class);
        $event = $events->createDraft(
            NationRaidAstragiaAdoptionService::APPROVED_EVENT_KEY,
            NationRaidRules::EVENT_NAME,
            now()->setDate(2026, 9, 25)->setTime(9, 0),
        );
        $event = $events->approveBalance($event, $admin, 'fixture approval');
        $event = $events->schedule($event, now());
        $rules = app(NationRaidRules::class);
        $event->update([
            'name' => '国家対抗レイド 黒天竜ヴァルグレイド',
            'boss_name' => '十系喰らいの黒天竜 ヴァルグレイド',
            'ruleset_version' => NationRaidRules::PREVIOUS_NEXT_CYCLE_RULESET_VERSION,
            'ruleset_snapshot' => $rules->previousNextCycleRulesetSnapshot(),
            'ruleset_hash' => $rules->previousNextCycleRulesetHash(),
        ]);

        return [$event->fresh(), $admin];
    }

    private function commandArguments(NationRaidEvent $event, User $admin): array
    {
        $rules = app(NationRaidRules::class);

        return [
            '--event-id' => $event->id,
            '--event-key' => NationRaidAstragiaAdoptionService::APPROVED_EVENT_KEY,
            '--admin-id' => $admin->id,
            '--approval-reference' => '2026-09-16 approved Astragia boss and four form assets',
            '--expected-old-ruleset-hash' => $rules->previousNextCycleRulesetHash(),
            '--new-ruleset-hash' => $rules->rulesetHash(),
            '--confirm-astragia-adoption' => true,
        ];
    }
}
