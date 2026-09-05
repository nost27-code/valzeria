<?php

namespace Tests\Feature;

use App\Models\Nation;
use App\Models\NationRaidBattleResult;
use App\Models\NationRaidEvent;
use App\Models\NationRaidParticipation;
use App\Services\Nation\Raid\NationRaidEventService;
use App\Services\Nation\Raid\NationRaidRankingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class NationRaidRankingTest extends TestCase
{
    use RefreshDatabase;

    public function test_applied_damage_is_authoritative_and_coordination_only_increases_nation_total(): void
    {
        $event = $this->event();
        $nation = Nation::query()->create(['name' => '集計国', 'nation_type' => 'kingdom', 'status' => 'active', 'founded_at' => now()]);
        $a = $this->participant($event, 1, $nation->id, 2);
        $b = $this->participant($event, 2, $nation->id, 2);
        $outsider = $this->participant($event, 3);
        $this->battle($a, 100, 12, 60);
        $this->battle($b, 50, 3, 40);
        $this->battle($outsider, 200, 0, 90);
        $this->battle($a, 999, 0, 999, 'refunded');
        // 読取集計は参加者cacheの誤値や、計算のみで未適用の値を信用しない。
        $a->update(['personal_damage_total' => 999_999, 'resolved_sorties' => 35]);
        $result = app(NationRaidRankingService::class)->standings($event);
        $this->assertSame([200, 100, 50], array_column($result['personal_total'], 'damage'));
        $this->assertSame([90, 60, 40], array_column($result['max_action'], 'damage'));
        $this->assertSame(165, $result['nation_total'][0]['damage']);
        $this->assertSame(150, $result['nation_per_capita'][0]['damage']);
        $this->assertSame(2, $result['nation_per_capita'][0]['denominator']);
        $this->assertSame(200, $result['unaffiliated_damage']);
        $this->assertSame(0, $result['nation_total'][0]['eligible_participant_count']);
    }

    public function test_ties_use_competition_ranks_not_internal_ids_and_keep_snapshot_names(): void
    {
        $event = $this->event();
        $a = $this->participant($event, 1);
        $b = $this->participant($event, 2);
        $c = $this->participant($event, 3);
        $this->battle($a, 100, 0, 20);
        $this->battle($b, 100, 0, 20);
        $this->battle($c, 90, 0, 10);
        $snapshot = app(NationRaidRankingService::class)->standings($event);
        $snapshot['is_final'] = true;
        $event->update(['status' => 'completed', 'final_standings_snapshot' => $snapshot,
            'final_standings_hash' => app(\App\Services\Nation\Raid\NationRaidRewardPolicy::class)->hash($snapshot)]);
        $first = app(NationRaidRankingService::class)->standings($event);
        $this->assertSame([1, 1, 3], array_column($first['personal_total'], 'rank'));
        $this->assertSame(['冒険者1', '冒険者2', '冒険者3'], array_column($first['personal_total'], 'name'));
        $this->assertTrue($first['is_final']);
        $this->assertSame($first, app(NationRaidRankingService::class)->standings($event->fresh()));
    }

    public function test_ratio_order_is_exact_above_double_integer_precision_and_zero_denominator_is_unranked(): void
    {
        $service = app(NationRaidRankingService::class);
        $this->assertSame(1, $service->compareRatios(9_007_199_254_740_993, 3, 9_007_199_254_740_992, 3));
        $this->assertSame(0, $service->compareRatios(100, 2, 150, 3));
        $this->assertSame(-1, $service->compareRatios(PHP_INT_MAX - 1, 3, PHP_INT_MAX, 3));
        $this->assertSame(-1, $service->compareRatios(1, 2, 2, 3));
        $this->assertSame(1, $service->compareRatios(3, 2, 4, 3));
        $this->assertSame(0, $service->compareRatios(4, 6, 2, 3));
        $event = $this->event();
        $nation = Nation::query()->create(['name' => '基準不明国', 'nation_type' => 'kingdom', 'status' => 'active', 'founded_at' => now()]);
        $this->battle($this->participant($event, 1, $nation->id, 0), 100, 0, 10);
        $rows = $service->standings($event);
        $this->assertSame(1, $rows['nation_total'][0]['rank']);
        $this->assertNull($rows['nation_per_capita'][0]['rank']);
    }

    public function test_disbanded_nation_keeps_frozen_name_and_eligible_count_uses_resolved_sorties(): void
    {
        $event = $this->event();
        $nation = Nation::query()->create(['name' => '旧国', 'nation_type' => 'kingdom', 'status' => 'active', 'founded_at' => now()]);
        $participant = $this->participant($event, 1, $nation->id, 1);
        foreach (range(1, 15) as $i) {
            $this->battle($participant, 10, 0, 10);
        }
        $nation->update(['name' => '変更後', 'status' => 'disbanded']);
        $row = app(NationRaidRankingService::class)->standings($event)['nation_total'][0];
        $this->assertSame('開始時の国家名', $row['name']);
        $this->assertSame(1, $row['eligible_participant_count']);
        $this->assertSame(150, $row['damage']);
    }

    public function test_conflicting_frozen_denominators_fail_instead_of_fabricating_per_capita_ranks(): void
    {
        $event = $this->event();
        $nation = Nation::query()->create(['name' => '基準不一致国', 'nation_type' => 'kingdom', 'status' => 'active', 'founded_at' => now()]);
        $this->battle($this->participant($event, 1, $nation->id, 2), 100, 0, 10);
        $this->battle($this->participant($event, 2, $nation->id, 3), 100, 0, 10);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('国家基準人数');
        app(NationRaidRankingService::class)->standings($event);
    }

    private function event(): NationRaidEvent
    {
        return app(NationRaidEventService::class)->createDraft('ranking-'.bin2hex(random_bytes(6)), '国家対抗レイド', now());
    }

    private function participant(NationRaidEvent $event, int $account, ?int $nationId = null, int $reference = 0): NationRaidParticipation
    {
        return NationRaidParticipation::query()->create([
            'event_id' => $event->id, 'account_id' => $account, 'character_id' => null,
            'nation_id' => $nationId, 'is_nation_eligible' => $nationId !== null,
            'reference_active_count' => $reference, 'character_name_snapshot' => '冒険者'.$account,
            'nation_name_snapshot' => $nationId !== null ? '開始時の国家名' : null,
        ]);
    }

    private function battle(NationRaidParticipation $p, int $damage, int $bonus, int $max, string $status = 'resolved'): void
    {
        NationRaidBattleResult::query()->create([
            'event_id' => $p->event_id, 'participation_id' => $p->id, 'account_id' => $p->account_id,
            'battle_token' => bin2hex(random_bytes(32)), 'sortie_seed' => str_repeat('a', 64),
            'status' => $status, 'nation_id' => $p->nation_id, 'raid_day' => 1,
            'day_sortie_no' => 1, 'event_sortie_no' => 1, 'target_cycle_no' => 1,
            'target_cycle_kind' => 'main', 'target_stage_no' => 1, 'target_form' => 'sealed_scale',
            'target_parameter_snapshot' => [], 'boss_species_key' => 'dragon', 'strategy' => 'assault',
            'calculated_damage_total' => $damage + 12345, 'applied_damage_total' => $damage,
            'coordination_damage_total' => $bonus, 'nation_damage_total' => $p->is_nation_eligible ? $damage + $bonus : 0,
            'max_action_damage' => $max, 'started_at' => now(), 'resolution_deadline_at' => now()->addMinutes(10), 'resolved_at' => now(),
        ]);
    }
}
