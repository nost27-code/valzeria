<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\NationRaidEvent;
use App\Models\User;
use App\Services\Nation\Raid\NationRaidEventService;
use App\Services\Nation\Raid\NationRaidJson;
use App\Services\Nation\Raid\NationRaidLocalEventUpgradeService;
use App\Services\Nation\Raid\NationRaidSortieService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class NationRaidLocalEventUpgradeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance('env', 'local');
        $this->travelTo(now()->setDate(2030, 1, 10)->setTime(9, 0));
        config(['features.nation_competitive_raid_enabled' => true, 'features.nation_war_enabled' => false]);
        foreach (['dynamic_single', 'hit_resolution', 'damage_application', 'resources'] as $flag) {
            config()->set("battle.job_art_v2.{$flag}", true);
        }
    }

    protected function tearDown(): void
    {
        foreach (Character::pluck('id') as $id) {
            \App\Services\CharacterStatusService::clearRequestCache((int) $id);
        }
        $this->travelBack();
        parent::tearDown();
    }

    public function test_upgrade_preserves_damage_history_rewards_and_assets_and_is_idempotent(): void
    {
        [$event, $character] = $this->fixture();
        $sorties = app(NationRaidSortieService::class);
        $old = $sorties->fight($event, $character, 'boss_set', bin2hex(random_bytes(32)));
        $this->assertSame('resolved', $old->status);
        $before = $this->preservedRows();
        $policy = $event->reward_policy_hash;
        $cycle = $event->cycles()->sole();
        $service = app(NationRaidLocalEventUpgradeService::class);
        $result = $service->upgrade($event->id, $event->event_key);
        try {
            $this->assertTrue($result['changed']);
            $this->assertSame(10_000_000 - $old->applied_damage_total, $result['current_hp']);
            $this->assertSame($before, $this->preservedRows());
            $this->assertSame($policy, $event->fresh()->reward_policy_hash);
            $this->assertSame(6_920_000_000, $event->fresh()->total_target_hp);
            $this->assertSame(10_000_000, $cycle->fresh()->parameter_snapshot['boss']['max_hp']);
            $this->assertSame($result['backup_sha256'], hash_file('sha256', $result['backup_path']));
            $saved = json_decode(File::get($result['backup_path']), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(5_000_000, $saved['cycle']['max_hp']);
            $this->assertFalse($service->upgrade($event->id, $event->event_key)['changed']);
            $this->assertSame($before, $this->preservedRows());
            // 旧tokenの再送は旧結果を返すだけ。移行後の新出撃は新HPで計算する。
            $this->assertSame($old->summary, $sorties->fight($event->fresh(), $character, 'boss_set', $old->battle_token)->summary);
            $new = $sorties->fight($event->fresh(), $character, 'boss_set', bin2hex(random_bytes(32)));
            $this->assertSame('resolved', $new->status);
            $this->assertSame(10_000_000, $new->summary['admission']['encounter']['max_hp']);
        } finally {
            File::delete($result['backup_path']);
        }
    }

    public function test_pending_sortie_blocks_upgrade_without_changing_anything(): void
    {
        [$event, $character] = $this->fixture();
        app(NationRaidSortieService::class)->start($event, $character, 'boss_set', bin2hex(random_bytes(32)));
        $before = $event->fresh()->getAttributes();
        try {
            app(NationRaidLocalEventUpgradeService::class)->upgrade($event->id, $event->event_key);
            $this->fail('Pending admission must block upgrade.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('未確定出撃', $e->getMessage());
        }
        $this->assertSame($before, $event->fresh()->getAttributes());
    }

    public function test_production_is_rejected_before_any_lookup_or_mutation(): void
    {
        $this->app->instance('env', 'production');
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('ローカルCLI');
        app(NationRaidLocalEventUpgradeService::class)->upgrade(1, 'local-anything');
    }

    public function test_wrong_event_key_is_rejected(): void
    {
        [$event] = $this->fixture();
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('対象の開催中');
        app(NationRaidLocalEventUpgradeService::class)->upgrade($event->id, 'local-wrong');
    }

    private function fixture(): array
    {
        $character = Character::create([
            'user_id' => User::factory()->create()->id, 'name' => '移行確認', 'level' => 30,
            'hp_base' => 20_000, 'mp_base' => 500, 'attack_base' => 3_000, 'defense_base' => 3_000,
            'magic_base' => 500, 'spirit_base' => 3_000, 'speed_base' => 1_000, 'luck_base' => 100,
            'current_hp' => 10, 'current_mp' => 1, 'explore_stamina' => 250, 'explore_stamina_max' => 250,
            'explore_stamina_updated_at' => now(), 'last_battle_at' => now(),
        ]);
        $events = app(NationRaidEventService::class);
        $event = $events->createDraft('local-upgrade-test', 'ローカル検証', now());
        $event = $events->approveBalance($event, User::factory()->create(['role' => 'admin']), 'fixture only');
        $event = $events->activate($events->schedule($event, now()->subHours(72)));
        $snapshot = $event->ruleset_snapshot;
        $snapshot['version'] = 'nation-raid-phase1-v4-equipment-resistance';
        $snapshot['fixed']['boss_max_hp'] = 5_000_000;
        unset($snapshot['fixed']['total_target_hp']);
        foreach ($snapshot['stages'] as &$stage) {
            unset($stage['max_hp']);
        }
        unset($stage);
        $event->update(['ruleset_snapshot' => $snapshot, 'ruleset_version' => $snapshot['version'],
            'ruleset_hash' => hash('sha256', NationRaidJson::encode($snapshot, JSON_UNESCAPED_UNICODE)),
            'cycle_max_hp' => 5_000_000, 'total_target_hp' => 100_000_000]);
        $event->cycles()->sole()->update(['max_hp' => 5_000_000, 'current_hp' => 5_000_000,
            'parameter_snapshot' => $events->cycleParameterSnapshot(1, $event)]);
        return [$event, $character];
    }

    private function preservedRows(): array
    {
        $out = [];
        foreach (['characters', 'nation_raid_battle_results', 'nation_raid_participations', 'nation_raid_daily_usages',
            'nation_raid_personal_rewards', 'nation_raid_nation_rewards'] as $table) {
            $out[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        return $out;
    }
}
