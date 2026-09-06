<?php

namespace Tests\Feature;

use App\Models\NationRaidBossCycle;
use App\Models\User;
use App\Services\Nation\Raid\NationRaidApprovedHpCurveUpgradeService;
use App\Services\Nation\Raid\NationRaidEventService;
use App\Services\Nation\Raid\NationRaidJson;
use App\Services\Nation\Raid\NationRaidRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class NationRaidApprovedHpCurveUpgradeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2030, 1, 10)->setTime(9, 0));
        config(['features.nation_competitive_raid_enabled' => true, 'features.nation_war_enabled' => false]);
        foreach (['dynamic_single', 'hit_resolution', 'damage_application', 'resources'] as $flag) {
            config()->set("battle.job_art_v2.{$flag}", true);
        }
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_upgrade_preserves_completed_cycles_and_current_damage_and_is_idempotent(): void
    {
        [$event, $admin] = $this->oldCurveEventAtStageNine();
        $completedBefore = $event->cycles()->whereNotNull('defeated_at')->orderBy('cycle_no')->get()->toJson();
        $reference = 'User 2026-09-06: stages 9-12=200m, 13-16=500m, 17-20=1b; live damage preserved.';
        $service = app(NationRaidApprovedHpCurveUpgradeService::class);
        $result = $service->upgrade($event->id, $event->event_key,
            app(NationRaidRules::class)->previousStagedHpRulesetHash(), $admin, $reference);
        try {
            $this->assertTrue($result['changed']);
            $this->assertSame(10_000_000, $result['applied_damage_preserved']);
            $this->assertSame(190_000_000, $result['current_hp']);
            $this->assertSame($completedBefore, $event->cycles()->whereNotNull('defeated_at')->orderBy('cycle_no')->get()->toJson());
            $event->refresh();
            $current = $event->cycles()->where('cycle_no', 9)->sole();
            $this->assertSame(6_920_000_000, $event->total_target_hp);
            $this->assertSame(app(NationRaidRules::class)->rulesetHash(), $event->ruleset_hash);
            $this->assertSame($reference, $event->balance_approval_reference);
            $this->assertSame(200_000_000, $current->max_hp);
            $this->assertSame(190_000_000, $current->current_hp);
            $this->assertSame($event->ruleset_hash, $current->parameter_snapshot['ruleset_hash']);
            $this->assertSame($result['backup_sha256'], hash_file('sha256', $result['backup_path']));
            $this->assertFalse($service->upgrade($event->id, $event->event_key,
                app(NationRaidRules::class)->previousStagedHpRulesetHash(), $admin, $reference)['changed']);
        } finally {
            File::delete($result['backup_path']);
        }
    }

    public function test_upgrade_rejects_an_event_that_already_completed_stage_nine(): void
    {
        [$event, $admin] = $this->oldCurveEventAtStageNine();
        $cycle = $event->cycles()->where('cycle_no', 9)->sole();
        $cycle->update(['current_hp' => 0, 'defeated_at' => now()]);
        $event->update(['current_cycle_no' => 10]);
        $event->cycles()->create([
            'cycle_no' => 10, 'cycle_kind' => NationRaidBossCycle::KIND_MAIN, 'stage_no' => 10,
            'max_hp' => 30_000_000, 'current_hp' => 30_000_000, 'current_form' => NationRaidRules::FORM_SEALED_SCALE,
            'boss_species_key' => 'dragon', 'parameter_snapshot' => $this->oldCycleSnapshot($event->fresh(), 10),
            'started_at' => now(),
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('第9再臨以降が討伐済み');
        app(NationRaidApprovedHpCurveUpgradeService::class)->upgrade(
            $event->id, $event->event_key, app(NationRaidRules::class)->previousStagedHpRulesetHash(), $admin, 'approved');
    }

    private function oldCurveEventAtStageNine(): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $events = app(NationRaidEventService::class);
        $event = $events->createDraft('valgreid-inaugural', '初回レイド', now());
        $event = $events->approveBalance($event, $admin, 'old approved curve');
        $event = $events->activate($events->schedule($event, now()->subHours(72)));

        $snapshot = $event->ruleset_snapshot;
        $snapshot['version'] = 'nation-raid-v5-staged-hp';
        $snapshot['fixed']['total_target_hp'] = 600_000_000;
        foreach ($snapshot['stages'] as $index => &$stage) {
            $stage['max_hp'] = 10_000_000 * (1 + intdiv($index, 4));
        }
        unset($stage);
        $oldHash = hash('sha256', NationRaidJson::encode($snapshot, JSON_UNESCAPED_UNICODE));
        $event->update([
            'ruleset_version' => $snapshot['version'], 'ruleset_snapshot' => $snapshot, 'ruleset_hash' => $oldHash,
            'total_target_hp' => 600_000_000, 'current_cycle_no' => 9,
        ]);
        $first = $event->cycles()->sole();
        $first->update([
            'current_hp' => 0, 'defeated_at' => now(),
            'parameter_snapshot' => $this->oldCycleSnapshot($event->fresh(), 1),
        ]);
        foreach (range(2, 8) as $stage) {
            $hp = $stage <= 4 ? 10_000_000 : 20_000_000;
            $event->cycles()->create([
                'cycle_no' => $stage, 'cycle_kind' => NationRaidBossCycle::KIND_MAIN, 'stage_no' => $stage,
                'max_hp' => $hp, 'current_hp' => 0, 'current_form' => NationRaidRules::FORM_EXPOSED_CORE,
                'boss_species_key' => 'dragon', 'parameter_snapshot' => $this->oldCycleSnapshot($event->fresh(), $stage),
                'started_at' => now()->subMinute(), 'defeated_at' => now(),
            ]);
        }
        $event->cycles()->create([
            'cycle_no' => 9, 'cycle_kind' => NationRaidBossCycle::KIND_MAIN, 'stage_no' => 9,
            'max_hp' => 30_000_000, 'current_hp' => 20_000_000, 'current_form' => NationRaidRules::FORM_SPLIT_WING,
            'boss_species_key' => 'dragon', 'parameter_snapshot' => $this->oldCycleSnapshot($event->fresh(), 9),
            'started_at' => now(),
        ]);

        return [$event->fresh(), $admin];
    }

    private function oldCycleSnapshot($event, int $stage): array
    {
        return app(NationRaidEventService::class)->cycleParameterSnapshot($stage, $event);
    }
}
