<?php

namespace Tests\Feature;

use App\Models\Nation;
use App\Models\NationRaidCoordinationParticipant;
use App\Models\NationRaidParticipation;
use App\Models\User;
use App\Services\Nation\Raid\NationRaidApprovedCoordinationCurveUpgradeService;
use App\Services\Nation\Raid\NationRaidCoordinationService;
use App\Services\Nation\Raid\NationRaidEventService;
use App\Services\Nation\Raid\NationRaidJson;
use App\Services\Nation\Raid\NationRaidRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class NationRaidApprovedCoordinationCurveUpgradeTest extends TestCase
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

    public function test_upgrade_changes_only_the_curve_snapshot_and_is_idempotent(): void
    {
        [$event, $admin] = $this->oldCoordinationCurveEvent();
        $cycle = $event->cycles()->sole();
        $eventFields = ['total_target_hp', 'cycle_max_hp', 'current_cycle_no', 'status', 'starts_at', 'ends_at'];
        $cycleFields = ['max_hp', 'current_hp', 'current_form', 'started_at', 'defeated_at'];
        $eventBefore = $this->rawOnly($event, $eventFields);
        $cycleBefore = $this->rawOnly($cycle, $cycleFields);
        $reference = 'User 2026-09-06: coordination 2=3, 3=6, 4=9, 5-7=12, 8-11=15, 12-15=17, 16-18=19, 19-21=21, 22+=22 percent.';
        $service = app(NationRaidApprovedCoordinationCurveUpgradeService::class);
        $result = $service->upgrade(
            $event->id,
            $event->event_key,
            app(NationRaidRules::class)->previousLiveHpRulesetHash(),
            $admin,
            $reference,
        );

        try {
            $this->assertTrue($result['changed']);
            $event->refresh();
            $cycle->refresh();
            $this->assertSame($eventBefore, $this->rawOnly($event, $eventFields));
            $this->assertSame($cycleBefore, $this->rawOnly($cycle, $cycleFields));
            $this->assertSame(app(NationRaidRules::class)->rulesetHash(), $event->ruleset_hash);
            $this->assertSame(NationRaidRules::RULESET_VERSION, $event->ruleset_version);
            $this->assertSame(NationRaidRules::COORDINATION_DAMAGE_RATES,
                $event->ruleset_snapshot['fixed']['coordination_damage_rates']);
            $this->assertSame($event->ruleset_hash, $cycle->parameter_snapshot['ruleset_hash']);
            $this->assertSame($reference, $event->balance_approval_reference);
            $this->assertSame($result['backup_sha256'], hash_file('sha256', $result['backup_path']));
            $this->assertFalse($service->upgrade(
                $event->id,
                $event->event_key,
                app(NationRaidRules::class)->previousLiveHpRulesetHash(),
                $admin,
                $reference,
            )['changed']);
        } finally {
            File::delete($result['backup_path']);
        }
    }

    public function test_started_battle_hash_keeps_old_rate_while_new_event_hash_uses_new_rate(): void
    {
        [$event] = $this->oldCoordinationCurveEvent();
        $nation = Nation::create([
            'name' => '共闘確認国',
            'nation_type' => 'kingdom',
            'status' => 'active',
            'founded_at' => now(),
        ]);
        $participation = null;
        foreach (range(1, 21) as $characterId) {
            $member = NationRaidParticipation::create([
                'event_id' => $event->id,
                'account_id' => User::factory()->create()->id,
                'character_id_snapshot' => $characterId,
                'nation_id' => $nation->id,
                'nation_id_snapshot' => $nation->id,
                'nation_name_snapshot' => '共闘確認国',
                'character_name_snapshot' => '共闘者'.$characterId,
                'is_nation_eligible' => true,
            ]);
            NationRaidCoordinationParticipant::create([
                'event_id' => $event->id,
                'participation_id' => $member->id,
                'nation_id_snapshot' => $nation->id,
                'character_id_snapshot' => $characterId,
                'window_joined_at' => now(),
                'last_resolved_at' => now(),
            ]);
            $participation ??= $member;
        }

        $coordination = app(NationRaidCoordinationService::class);
        $oldHash = app(NationRaidRules::class)->previousLiveHpRulesetHash();
        $this->assertSame(0.12, $coordination->snapshot($event, $participation, false, $oldHash)['bonus_rate']);

        $event->update([
            'ruleset_version' => NationRaidRules::RULESET_VERSION,
            'ruleset_snapshot' => app(NationRaidRules::class)->rulesetSnapshot(),
            'ruleset_hash' => app(NationRaidRules::class)->rulesetHash(),
        ]);
        $this->assertSame(0.21, $coordination->snapshot($event->fresh(), $participation)['bonus_rate']);
    }

    public function test_upgrade_rejects_current_cycle_hp_mismatch(): void
    {
        [$event, $admin] = $this->oldCoordinationCurveEvent();
        $event->cycles()->sole()->update(['max_hp' => 9_999_999]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('snapshotが一致しません');
        app(NationRaidApprovedCoordinationCurveUpgradeService::class)->upgrade(
            $event->id,
            $event->event_key,
            app(NationRaidRules::class)->previousLiveHpRulesetHash(),
            $admin,
            'approved',
        );
    }

    private function oldCoordinationCurveEvent(): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $events = app(NationRaidEventService::class);
        $event = $events->createDraft('valgreid-inaugural', '初回レイド', now());
        $event = $events->approveBalance($event, $admin, 'old approved curve');
        $event = $events->activate($events->schedule($event, now()->subHours(72)));

        $snapshot = $event->ruleset_snapshot;
        $snapshot['version'] = 'nation-raid-v6-live-staged-hp';
        $snapshot['fixed']['coordination_damage_rates'] = [
            2 => 0.03, 3 => 0.06, 4 => 0.09, 5 => 0.12,
        ];
        $oldHash = hash('sha256', NationRaidJson::encode($snapshot, JSON_UNESCAPED_UNICODE));
        $event->update([
            'ruleset_version' => $snapshot['version'],
            'ruleset_snapshot' => $snapshot,
            'ruleset_hash' => $oldHash,
        ]);
        $cycle = $event->cycles()->sole();
        $cycle->update([
            'current_hp' => $cycle->max_hp - 123_456,
            'current_form' => app(NationRaidRules::class)->formForHp($cycle->max_hp - 123_456, $cycle->max_hp),
            'parameter_snapshot' => $events->cycleParameterSnapshot(1, $event->fresh()),
        ]);

        $this->assertSame(app(NationRaidRules::class)->previousLiveHpRulesetHash(), $oldHash);

        return [$event->fresh(), $admin];
    }

    private function rawOnly($model, array $keys): array
    {
        return array_intersect_key($model->getRawOriginal(), array_flip($keys));
    }
}
