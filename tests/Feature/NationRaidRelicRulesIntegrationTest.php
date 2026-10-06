<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\NationRaidBattleResult;
use App\Models\NationRaidBossCycle;
use App\Models\NationRaidDailyUsage;
use App\Models\NationRaidEvent;
use App\Models\PlayerNamelessEquipment;
use App\Models\PlayerRelic;
use App\Models\User;
use App\Services\Nation\Raid\NationRaidEventService;
use App\Services\Nation\Raid\NationRaidJson;
use App\Services\Nation\Raid\NationRaidRelicRules;
use App\Services\Nation\Raid\NationRaidRules;
use App\Services\Nation\Raid\NationRaidSortieCombatService;
use App\Services\Nation\Raid\NationRaidSortieService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NationRaidRelicRulesIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2030, 1, 10)->setTime(9, 0));
        config(['features.nation_competitive_raid_enabled' => true, 'features.nation_community_enabled' => true,
            'features.nation_development_enabled' => true, 'features.nation_war_enabled' => false]);
        foreach (['dynamic_single', 'hit_resolution', 'damage_application', 'resources'] as $flag) {
            config(["battle.job_art_v2.{$flag}" => true]);
        }
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_off_to_on_blocks_new_sorties_but_replay_and_pending_combat_keep_off_rules(): void
    {
        $this->toggleScenario(false, true);
    }

    public function test_on_to_off_blocks_new_sorties_but_pending_combat_and_snapshot_remain_identical(): void
    {
        $this->toggleScenario(true, false);
    }

    private function toggleScenario(bool $approved, bool $changed): void
    {
        config(['nameless_relics.enabled' => $approved]);
        $character = $this->character();
        $event = $this->event();
        $service = app(NationRaidSortieService::class);
        $token = bin2hex(random_bytes(32));
        [$battle, $created] = $service->start($event, $character, 'assault', $token);
        $this->assertTrue($created);
        $this->assertSame($approved, $battle->summary['admission']['relic_rules']['enabled']);
        $this->assertSame($approved, $battle->summary['admission']['player']['actor']['nameless_relics']['enabled']);
        $baseline = app(NationRaidSortieCombatService::class)->resolve($battle);
        $originalHash = $event->ruleset_hash;
        $originalSnapshot = $battle->summary;
        $assets = $character->fresh()->getRawOriginal();
        config(['nameless_relics.enabled' => $changed]);
        $this->assertNotSame($originalHash, app(NationRaidRules::class)->rulesetHash());
        $this->rejectNew($service, $event, $character);
        [$replay, $replayCreated] = $service->start($event, $character, 'assault', $token);
        $this->assertFalse($replayCreated);
        $this->assertSame($battle->id, $replay->id);
        $this->assertSame($originalSnapshot, $replay->summary);
        $this->assertSame($baseline, app(NationRaidSortieCombatService::class)->resolve($replay));
        $this->assertSame($changed, config('nameless_relics.enabled'));
        $this->assertSame($originalHash, $event->fresh()->ruleset_hash);
        $this->assertSame($assets, $character->fresh()->getRawOriginal());
        $this->assertSame(1, NationRaidDailyUsage::where('event_id', $event->id)->sum('used_count'));
        $this->assertSame(1, NationRaidBattleResult::where('event_id', $event->id)->count());
    }

    public function test_effect_semantics_change_invalidates_new_admission_and_preserves_prepared_combat(): void
    {
        config(['nameless_relics.enabled' => true]);
        $character = $this->character();
        $event = $this->event();
        $service = app(NationRaidSortieService::class);
        [$battle] = $service->start($event, $character, 'assault', bin2hex(random_bytes(32)));
        $baseline = app(NationRaidSortieCombatService::class)->resolve($battle);
        config(['nameless_relics.special_values.opener.9' => .01, 'nameless_relics.counter_damage_rate' => .01]);
        $this->rejectNew($service, $event, $character);
        $this->assertSame($baseline, app(NationRaidSortieCombatService::class)->resolve($battle));
        $this->assertSame(.01, config('nameless_relics.special_values.opener.9'));
        $this->assertSame(.01, config('nameless_relics.counter_damage_rate'));
    }

    public function test_socket_capacity_change_invalidates_admission_and_freezes_capture(): void
    {
        config(['nameless_relics.enabled' => true]);
        $character = $this->character();
        $event = $this->event();
        $rules = $event->ruleset_snapshot['nameless_relic_combat'];
        $expected = app(\App\Services\Nation\Raid\NationRaidPlayerPreparationService::class)->capture($character, $rules);
        config(['nameless_relics.slots_per_equipment' => 1, 'nameless_relics.ordinary_equipment_slots.SSS' => 1]);
        $this->assertNotSame($event->ruleset_hash, app(NationRaidRules::class)->rulesetHash());
        $this->rejectNew(app(NationRaidSortieService::class), $event, $character);
        $this->assertSame($expected, app(\App\Services\Nation\Raid\NationRaidPlayerPreparationService::class)->capture($character, $rules));
        $this->assertSame(1, config('nameless_relics.slots_per_equipment'));
        $this->assertSame(0, NationRaidBattleResult::where('event_id', $event->id)->count());
    }

    public function test_stale_draft_cannot_be_approved_after_toggle(): void
    {
        config(['nameless_relics.enabled' => false]);
        $service = app(NationRaidEventService::class);
        $event = $service->createDraft('stale-relic-draft', '検証', now());
        config(['nameless_relics.enabled' => true]);
        try {
            $service->approveBalance($event, User::factory()->create(['role' => 'admin']), 'test-only');
            $this->fail('Stale approval was accepted.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('下書きの戦闘ルールが変更', $exception->getMessage());
        }
        $this->assertNull($event->fresh()->balance_approved_at);
    }

    public function test_legacy_off_event_and_pending_snapshot_keep_original_hash_and_assets(): void
    {
        config(['nameless_relics.enabled' => false]);
        $character = $this->character();
        $event = $this->event();
        $rules = $event->ruleset_snapshot;
        unset($rules['nameless_relic_combat']);
        $hash = hash('sha256', NationRaidJson::encode($rules, JSON_UNESCAPED_UNICODE));
        $this->assertSame('75eb35e5d286559adbdde3c68496aada085d4632347b27a1040579fb3c67122a', $hash);
        $event->update(['ruleset_snapshot' => $rules, 'ruleset_hash' => $hash]);
        $cycle = NationRaidBossCycle::where('event_id', $event->id)->sole();
        $parameters = $cycle->parameter_snapshot;
        $parameters['ruleset_hash'] = $hash;
        $cycle->update(['parameter_snapshot' => $parameters]);
        [$battle] = app(NationRaidSortieService::class)->start($event, $character, 'assault', bin2hex(random_bytes(32)));
        $summary = $battle->summary;
        unset($summary['admission']['relic_rules'], $summary['admission']['player']['actor']['nameless_relics']);
        $battle->update(['summary' => $summary]);
        config(['nameless_relics.enabled' => true]);
        $resolved = app(NationRaidSortieCombatService::class)->resolve($battle);
        $this->assertSame($hash, $resolved['engine_result']['rulesetHash']);
        $this->assertSame($hash, $event->fresh()->ruleset_hash);
        $this->assertSame(1, NationRaidBattleResult::where('event_id', $event->id)->count());
    }

    public function test_frozen_scope_restores_config_and_caches_even_on_failure(): void
    {
        config(['nameless_relics.enabled' => true]);
        $rules = app(NationRaidRelicRules::class);
        $contract = $rules->current();
        config(['nameless_relics.enabled' => false, 'nameless_relics.counter_damage_rate' => .01,
            'nameless_relic_effects.stat_str.injected_semantics' => true]);
        try {
            $rules->frozen($contract, function () {
                $this->assertTrue(config('nameless_relics.enabled'));
                $this->assertSame(.25, config('nameless_relics.counter_damage_rate'));
                $this->assertNull(config('nameless_relic_effects.stat_str.injected_semantics'));
                throw new \RuntimeException('injected');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('injected', $exception->getMessage());
        }
        $this->assertFalse(config('nameless_relics.enabled'));
        $this->assertSame(.01, config('nameless_relics.counter_damage_rate'));
        $this->assertTrue(config('nameless_relic_effects.stat_str.injected_semantics'));
    }

    public function test_on_pending_sortie_settles_exactly_once_after_global_off(): void
    {
        config(['nameless_relics.enabled' => true]);
        $character = $this->character();
        $event = $this->event();
        [$battle] = app(NationRaidSortieService::class)->start($event, $character, 'assault', bin2hex(random_bytes(32)));
        $resources = [$character->fresh()->current_hp, $character->fresh()->current_mp, $character->fresh()->explore_stamina];
        config(['nameless_relics.enabled' => false]);
        $calculation = app(NationRaidSortieCombatService::class)->resolve($battle);
        $settlement = app(\App\Services\Nation\Raid\NationRaidSettlementService::class);
        $first = $settlement->resolve($battle, $calculation);
        $replay = $settlement->resolve($battle, $calculation);
        $this->assertSame('resolved', $first->status);
        $this->assertSame($first->applied_damage_total, $replay->applied_damage_total);
        $this->assertSame(1, NationRaidDailyUsage::where('event_id', $event->id)->sum('resolved_count'));
        $this->assertSame($resources, [$character->fresh()->current_hp, $character->fresh()->current_mp, $character->fresh()->explore_stamina]);
        $this->assertFalse(config('nameless_relics.enabled'));
    }

    public function test_unversioned_old_on_snapshot_refuses_combat_and_refunds_without_losing_assets(): void
    {
        config(['nameless_relics.enabled' => true]);
        $character = $this->character();
        $event = $this->event();
        [$battle] = app(NationRaidSortieService::class)->start($event, $character, 'assault', bin2hex(random_bytes(32)));
        $summary = $battle->summary;
        unset($summary['admission']['relic_rules']);
        $battle->update(['summary' => $summary]);
        try {
            app(NationRaidSortieCombatService::class)->resolve($battle);
            $this->fail('Unversioned relic combat was accepted.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('承認済み遺物ルールのない旧出撃', $exception->getMessage());
        }
        $settlement = app(\App\Services\Nation\Raid\NationRaidSettlementService::class);
        $first = $settlement->refund($battle, 'unversioned_relic_contract');
        $replay = $settlement->refund($battle, 'unversioned_relic_contract');
        $this->assertSame('refunded', $first->status);
        $this->assertSame($first->id, $replay->id);
        $this->assertSame(0, NationRaidDailyUsage::where('event_id', $event->id)->sum('used_count'));
        $this->assertSame(1, NationRaidDailyUsage::where('event_id', $event->id)->sum('refunded_count'));
        $this->assertSame(3, PlayerRelic::where('character_id', $character->id)->count());
        $this->assertSame(1, PlayerNamelessEquipment::where('character_id', $character->id)->count());
    }

    public function test_reservation_contract_is_used_even_when_toggle_happens_before_capture(): void
    {
        config(['nameless_relics.enabled' => true]);
        $character = $this->character();
        $event = $this->event();
        $service = app(NationRaidSortieService::class);
        [$battle] = $service->start($event, $character, 'assault', bin2hex(random_bytes(32)));
        $expected = $battle->summary['admission']['player'];
        $summary = $battle->summary;
        unset($summary['admission']['player'], $summary['admission']['prepared_at']);
        $battle->update(['summary' => $summary]);
        config(['nameless_relics.enabled' => false]);
        $prepared = (new \ReflectionMethod($service, 'preparePlayer'))->invoke($service, $battle, 0.0, 0.0);
        $this->assertSame($expected, $prepared->summary['admission']['player']);
        $this->assertFalse(config('nameless_relics.enabled'));
        $this->assertSame(1, NationRaidDailyUsage::where('event_id', $event->id)->sum('used_count'));
    }

    private function rejectNew(NationRaidSortieService $service, NationRaidEvent $event, Character $character): void
    {
        try {
            $service->start($event, $character, 'assault', bin2hex(random_bytes(32)));
            $this->fail('Unapproved relic change was accepted.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('承認時から変更', $exception->getMessage());
        }
    }

    private function event(): NationRaidEvent
    {
        $service = app(NationRaidEventService::class);
        $event = $service->createDraft('relic-'.bin2hex(random_bytes(6)), '隔離検証', now());
        $event = $service->approveBalance($event, User::factory()->create(['role' => 'admin']), 'test-only, not production approval');
        $event = $service->schedule($event, now()->subHours(72));
        return $service->activate($event);
    }

    private function character(): Character
    {
        $character = Character::create(['user_id' => User::factory()->create()->id, 'name' => '遺物契約検証',
            'level' => 255, 'hp_base' => 10000, 'mp_base' => 1000, 'current_hp' => 5000, 'current_mp' => 300,
            'attack_base' => 10000, 'defense_base' => 1000, 'magic_base' => 1000, 'spirit_base' => 1000,
            'speed_base' => 1000, 'luck_base' => 100, 'explore_stamina' => 250, 'explore_stamina_max' => 250,
            'explore_stamina_updated_at' => now()]);
        $body = PlayerNamelessEquipment::create(['character_id' => $character->id, 'kind' => 'weapon',
            'equipment_type' => '剣', 'base_power' => 5, 'is_equipped' => true]);
        foreach (['special_opener', 'special_counter', 'stat_str'] as $slot => $effect) {
            PlayerRelic::create(['character_id' => $character->id, 'effect_key' => $effect, 'rank' => 9,
                'nameless_equipment_id' => $body->id, 'slot_number' => $slot + 1]);
        }
        return $character;
    }
}
