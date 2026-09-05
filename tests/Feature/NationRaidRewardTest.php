<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckCharacterSelected;
use App\Models\Character;
use App\Models\CharacterConsumableItem;
use App\Models\CharacterMaterial;
use App\Models\KisekiTransaction;
use App\Models\Material;
use App\Models\Nation;
use App\Models\NationRaidBattleResult;
use App\Models\NationRaidEvent;
use App\Models\NationRaidNationReward;
use App\Models\NationRaidParticipation;
use App\Models\NationRaidPersonalReward;
use App\Models\User;
use App\Services\CharacterNotificationService;
use App\Services\Nation\Raid\NationRaidEventService;
use App\Services\Nation\Raid\NationRaidHonorService;
use App\Services\Nation\Raid\NationRaidOperationsService;
use App\Services\Nation\Raid\NationRaidRankingService;
use App\Services\Nation\Raid\NationRaidRewardService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class NationRaidRewardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('nation_raid_rewards', require base_path('scripts/verify/fixtures/nation_raid_rewards_v1.php'));
    }

    public function test_finalization_freezes_policy_ranks_and_entitlements_and_replays_without_granting_twice(): void
    {
        [$event, $character, $nation] = $this->scenario();
        config()->set('nation_raid.qualification.minimum_resolved_sorties', 35);
        $final = app(NationRaidEventService::class)->completeFinalization($event);
        $this->assertSame('completed', $final->status);
        $this->assertSame(15, $final->reward_policy_snapshot['minimum_resolved_sorties']);
        $ranks = app(NationRaidRankingService::class)->standings($final);
        $this->assertTrue($ranks['is_final']);
        $this->assertTrue($ranks['personal_total'][0]['qualified']);
        $this->assertSame(8, NationRaidPersonalReward::where('event_id', $event->id)->count());
        $this->assertSame(3, NationRaidNationReward::where('event_id', $event->id)->count());
        $this->assertSame(1000, $nation->fresh()->treasury_points);
        $this->assertSame(0, $nation->fresh()->development_exp);
        $this->assertDatabaseHas('nation_resource_transactions', ['nation_id' => $nation->id, 'transaction_type' => 'raid_reward', 'development_exp_delta' => 0]);
        // Claim rights are not assets, and changed calculation caches cannot alter frozen ranks.
        $this->assertSame(0, KisekiTransaction::count());
        $this->assertSame(0, CharacterConsumableItem::where('character_id', $character->id)->count());
        $event->participations()->update(['personal_damage_total' => 1]);
        app(NationRaidEventService::class)->completeFinalization($event);
        $this->assertSame($ranks, app(NationRaidRankingService::class)->standings($event->fresh()));
        $this->assertSame(1, DB::table('nation_resource_transactions')->where('transaction_type', 'raid_reward')->count());
    }

    public function test_personal_claims_are_atomic_audited_and_choices_are_independent_without_expiry(): void
    {
        [$event, $character] = $this->scenario();
        app(NationRaidEventService::class)->completeFinalization($event);
        config()->set('features.nation_competitive_raid_enabled', true);
        foreach (['participation', 'stage10', 'completion'] as $key) {
            $reward = $this->reward($event, $key);
            app(NationRaidRewardService::class)->claim($event, $character, $reward->id);
            app(NationRaidRewardService::class)->claim($event, $character, $reward->id);
        }
        $this->assertDatabaseHas('character_consumable_items', ['character_id' => $character->id, 'item_key' => 'explore_stamina_small_bottle', 'quantity' => 8]);
        $this->assertSame(5, (int) $character->fresh()->free_kiseki);
        $this->assertSame(7, (int) $character->fresh()->paid_kiseki);
        $this->assertSame(12, (int) $character->fresh()->kiseki);
        $this->assertSame(1, KisekiTransaction::where('transaction_type', 'nation_raid_reward')->count());
        $this->assertSame(3, DB::table('character_notifications')->where('type', 'nation_raid_reward_claimed')->count());
        $material = Material::query()->firstOrCreate(['material_code' => 'MAT_ENHANCE_FRAGMENT'], ['name' => '強化石の欠片', 'category' => 'enhance']);
        $this->travel(2)->years();
        foreach (['damage250k', 'damage1m'] as $key) {
            app(NationRaidRewardService::class)->claim($event, $character, $this->reward($event, $key)->id, 'enhance');
        }
        $this->assertSame(8, (int) CharacterMaterial::where('character_id', $character->id)->where('material_id', $material->id)->value('quantity'));
        $this->assertSame('enhance', $this->reward($event, 'damage1m')->selection_key);
        $this->expectException(\DomainException::class);
        app(NationRaidRewardService::class)->claim($event, $character, $this->reward($event, 'damage1m')->id, 'talisman');
    }

    public function test_off_gate_owner_checks_invalid_choices_and_full_storage_preserve_rights(): void
    {
        [$event, $character] = $this->scenario();
        app(NationRaidEventService::class)->completeFinalization($event);
        $service = app(NationRaidRewardService::class);
        $reward = $this->reward($event, 'damage250k');
        config()->set('features.nation_competitive_raid_enabled', false);
        $this->reject(fn () => $service->claim($event, $character, $reward->id, 'enhance'), '準備中');
        config()->set('features.nation_competitive_raid_enabled', true);
        $outsider = $this->character();
        $this->reject(fn () => $service->claim($event, $outsider, $reward->id, 'enhance'), '受取人');
        $this->reject(fn () => $service->claim($event, $character, $reward->id, 'talisman'), '選択');
        $material = Material::query()->firstOrCreate(['material_code' => 'MAT_ENHANCE_FRAGMENT'], ['name' => '強化石の欠片', 'category' => 'enhance']);
        CharacterMaterial::create(['character_id' => $character->id, 'material_id' => $material->id, 'quantity' => 500]);
        $this->reject(fn () => $service->claim($event, $character, $reward->id, 'enhance'), '倉庫');
        $this->assertSame('pending', $reward->fresh()->status);
        $this->assertNull($reward->fresh()->selection_key);
        $this->assertSame(500, (int) CharacterMaterial::where('character_id', $character->id)->value('quantity'));
    }

    public function test_claim_reads_the_locked_owner_before_opening_any_consistent_read_snapshot(): void
    {
        [$event, $character] = $this->scenario();
        app(NationRaidEventService::class)->completeFinalization($event);
        config()->set('features.nation_competitive_raid_enabled', true);
        $reward = $this->reward($event, 'participation');
        $connection = DB::connection();
        $dispatcher = $connection->getEventDispatcher();
        $scoped = clone $dispatcher;
        $selects = [];
        $scoped->listen(QueryExecuted::class, function (QueryExecuted $query) use (&$selects): void {
            if (preg_match('/^select\b/i', $query->sql)) {
                $selects[] = $query->sql;
            }
        });
        $connection->setEventDispatcher($scoped);
        try {
            app(NationRaidRewardService::class)->claim($event, $character, $reward->id);
        } finally {
            $connection->setEventDispatcher($dispatcher);
        }

        // SQLite fixes the executed query order; the MariaDB barrier test proves RR visibility.
        $this->assertMatchesRegularExpression('/from ["`]characters["`]/i', $selects[0]);
        $this->assertMatchesRegularExpression('/from ["`]nation_raid_events["`]/i', $selects[1]);
        $this->assertMatchesRegularExpression('/from ["`]nation_raid_personal_rewards["`]/i', $selects[2]);
        $this->assertSame($dispatcher, $connection->getEventDispatcher());
    }

    public function test_notification_failure_rolls_back_currency_bottles_and_claim_record(): void
    {
        [$event, $character] = $this->scenario();
        app(NationRaidEventService::class)->completeFinalization($event);
        config()->set('features.nation_competitive_raid_enabled', true);
        $this->mock(CharacterNotificationService::class)->shouldReceive('create')->once()->andThrow(new \RuntimeException('injected notification failure'));
        try {
            app(NationRaidRewardService::class)->claim($event, $character, $this->reward($event, 'completion')->id);
            $this->fail('Expected rollback');
        } catch (\RuntimeException $exception) {
            $this->assertSame('injected notification failure', $exception->getMessage());
        }
        $this->assertSame(0, (int) $character->fresh()->free_kiseki);
        $this->assertSame(0, KisekiTransaction::count());
        $this->assertSame(0, CharacterConsumableItem::count());
        $this->assertSame('pending', $this->reward($event, 'completion')->status);
    }

    public function test_finalization_rejects_missing_policy_votes_and_pending_sorties(): void
    {
        [$event] = $this->scenario();
        $service = app(NationRaidEventService::class);
        $hash = $event->reward_policy_hash;
        $event->update(['reward_policy_hash' => str_repeat('0', 64)]);
        $this->reject(fn () => $service->completeFinalization($event), '報酬');
        $event->update(['reward_policy_hash' => $hash]);
        DB::table('nation_raid_daily_lineage_snapshots')->where('event_id', $event->id)->where('raid_day', 7)->update(['determined_at' => null]);
        $this->reject(fn () => $service->completeFinalization($event), '系譜');
        DB::table('nation_raid_daily_lineage_snapshots')->where('event_id', $event->id)->update(['determined_at' => now()]);
        $battle = $event->battleResults()->first();
        foreach (['started', 'aborted'] as $status) {
            $battle->update(['status' => $status]);
            $this->reject(fn () => $service->completeFinalization($event), '未確定');
        }
        $this->assertSame(0, NationRaidPersonalReward::count());
        $this->assertSame('finalizing', $event->fresh()->status);
    }

    public function test_missing_ready_notification_stops_finalization_without_partial_rewards(): void
    {
        [$event, , $nation] = $this->scenario();
        $this->mock(CharacterNotificationService::class)->shouldReceive('create')->once()->andReturnNull();
        try {
            app(NationRaidEventService::class)->completeFinalization($event);
            $this->fail('Finalization must require a saved reward notification.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('報酬通知を保存できません。', $exception->getMessage());
        }
        $this->assertSame('finalizing', $event->fresh()->status);
        $this->assertNull($event->fresh()->final_standings_snapshot);
        $this->assertDatabaseCount('nation_raid_personal_rewards', 0);
        $this->assertDatabaseCount('nation_raid_nation_rewards', 0);
        $this->assertSame(0, $nation->fresh()->treasury_points);
    }

    public function test_failure_after_nation_grants_rolls_back_the_entire_finalization_then_can_retry(): void
    {
        [$event, , $nation] = $this->scenario();
        // 通知/資材/年表を保存した後、completed更新の直後に失敗させる。
        // Listenerは専用cloneに閉じ、後続テストのModelイベントを汚染しない。
        $dispatcher = NationRaidEvent::getEventDispatcher();
        NationRaidEvent::setEventDispatcher(clone $dispatcher);
        try {
            NationRaidEvent::updated(function (NationRaidEvent $saved): void {
                if ($saved->status === NationRaidEvent::STATUS_COMPLETED) {
                    throw new \RuntimeException('injected final commit failure');
                }
            });
            try {
                app(NationRaidEventService::class)->completeFinalization($event);
                $this->fail('Expected full finalization rollback.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('injected final commit failure', $exception->getMessage());
            }
        } finally {
            NationRaidEvent::setEventDispatcher($dispatcher);
        }
        $this->assertSame('finalizing', $event->fresh()->status);
        $this->assertNull($event->fresh()->final_standings_hash);
        $this->assertSame(0, $nation->fresh()->treasury_points);
        foreach (['nation_raid_personal_rewards', 'nation_raid_nation_rewards', 'nation_resource_transactions',
            'nation_achievements', 'nation_activity_logs', 'character_notifications'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        app(NationRaidEventService::class)->completeFinalization($event);
        $this->assertSame('completed', $event->fresh()->status);
        $this->assertSame(1000, $nation->fresh()->treasury_points);
        $this->assertSame(1, DB::table('nation_resource_transactions')->where('transaction_type', 'raid_reward')->count());
    }

    public function test_honors_are_ability_free_and_ties_receive_the_same_honor_without_per_capita_prizes(): void
    {
        [$event, $character, $nation] = $this->scenario();
        $other = $this->character();
        $this->addPlayer($event, $other, null);
        app(NationRaidEventService::class)->completeFinalization($event);
        $this->assertSame(2, NationRaidPersonalReward::where('reward_key', 'personal_first')->count());
        $this->assertSame(2, NationRaidPersonalReward::where('reward_key', 'max_first')->count());
        $this->assertSame(0, NationRaidNationReward::where('reward_key', 'like', '%per_capita%')->count());
        config()->set('features.nation_competitive_raid_enabled', true);
        app(NationRaidRewardService::class)->claim($event, $character, $this->reward($event, 'damage2m')->id);
        $this->assertDatabaseHas('titles', ['unlock_type' => 'nation_raid_honor', 'target_id' => 'damage2m', 'name' => '黒天竜を穿つ者']);
        $this->assertSame(1, $character->titles()->count());
        $this->assertSame(0, $nation->fresh()->development_exp);
        $this->assertDatabaseHas('nation_achievements', ['nation_id' => $nation->id, 'achievement_key' => 'valgreid_defeat_participation']);
    }

    public function test_unqualified_high_rank_does_not_take_rewards_and_milestones_do_not_repeat_for_echoes(): void
    {
        [$event, $character, $nation] = $this->scenario();
        $outsider = $this->character();
        $this->addPlayer($event, $outsider, null);
        $battles = $event->battleResults()->where('character_id', $outsider->id);
        (clone $battles)->latest('id')->first()->update(['status' => 'refunded']);
        $battles->where('status', 'resolved')->update(['applied_damage_total' => 1_000_000]);
        $event->update(['echo_defeated_count' => 100]);
        app(NationRaidEventService::class)->completeFinalization($event);
        $this->assertSame(0, NationRaidPersonalReward::where('character_id_snapshot', $outsider->id)->count());
        $this->assertDatabaseHas('nation_raid_personal_rewards', ['character_id_snapshot' => $character->id, 'reward_key' => 'personal_top3']);
        $this->assertSame(1, NationRaidPersonalReward::where('reward_key', 'completion')->count());
        $this->assertSame(1000, $nation->fresh()->treasury_points);
    }

    public function test_stage10_only_gives_six_bottles_and_no_completion_kiseki_or_nation_achievement(): void
    {
        [$event] = $this->scenario();
        $event->update(['completed_at' => null]);
        app(NationRaidEventService::class)->completeFinalization($event);
        $this->assertSame(0, NationRaidPersonalReward::where('reward_key', 'completion')->count());
        $this->assertSame(6, NationRaidPersonalReward::all()->sum(fn ($r) => $r->reward_snapshot['bottles'] ?? 0));
        $this->assertDatabaseCount('nation_achievements', 0);
    }

    public function test_nation_resource_qualification_excludes_coordination_and_applies_participant_cap(): void
    {
        [$event, $character, $nation] = $this->scenario();
        $event->participations()->update(['reference_active_count' => 2]);
        // 900万 / 2人 = 450万、到達6,000pt。人数cap 2,000と有効参加1人cap 1,500の小さい方。
        $event->battleResults()->update(['applied_damage_total' => 600_000, 'coordination_damage_total' => 72_000, 'nation_damage_total' => 672_000]);
        app(NationRaidEventService::class)->completeFinalization($event);
        $this->assertSame(1500, $nation->fresh()->treasury_points);
        $this->assertSame(9_000_000, $event->fresh()->final_standings_snapshot['nation_per_capita'][0]['damage']);

        [$second, , $secondNation] = $this->scenario();
        // 240,000は最初の閾値未満。連携を含む268,800では判定しない。
        $second->battleResults()->update(['applied_damage_total' => 16_000, 'max_action_damage' => 10_000,
            'coordination_damage_total' => 1920, 'nation_damage_total' => 17_920]);
        app(NationRaidEventService::class)->completeFinalization($second);
        $this->assertSame(0, $secondNation->fresh()->treasury_points);
        $this->assertSame(0, NationRaidNationReward::where('event_id', $second->id)->where('reward_key', 'resources')->count());
    }

    public function test_frozen_identity_is_retained_after_live_links_are_lost_and_never_transferred(): void
    {
        [$event, $character, $nation] = $this->scenario();
        // FK null化の再現。新しい所属先へ権利を移さない。
        $event->participations()->update(['character_id' => null, 'nation_id' => null]);
        app(NationRaidEventService::class)->completeFinalization($event);
        $this->assertSame(8, NationRaidPersonalReward::where('character_id_snapshot', $character->id)->count());
        $this->assertSame(1000, $nation->fresh()->treasury_points);
    }

    public function test_owner_http_reward_page_prg_and_closed_gate(): void
    {
        // このfixtureは初回ヴァルモン選択を作らない。authと所有権は有効のまま。
        $this->withoutMiddleware(CheckCharacterSelected::class);
        $this->withoutMiddleware(PreventRequestForgery::class);
        [$event, $character] = $this->scenario();
        app(NationRaidEventService::class)->completeFinalization($event);
        $this->actingAs($character->user);
        config()->set('features.nation_competitive_raid_enabled', false);
        $this->get(route('nation-raid.rewards', $event))->assertNotFound();
        $url = route('nation-raid.rewards.claim', ['event' => $event, 'reward' => $this->reward($event, 'completion')->id]);
        $this->post($url)->assertNotFound();
        config()->set('features.nation_competitive_raid_enabled', true);
        $this->get(route('nation-raid.show', $event))->assertRedirect(route('nation-raid.rewards', $event));
        $this->get(route('nation-raid.rewards', $event))->assertOk()->assertSee('戦利品を選ぶ')->assertSee('未受取')->assertSee('最終戦績');
        $this->post($url)->assertRedirect(route('nation-raid.rewards', $event));
        $this->post($url)->assertRedirect(route('nation-raid.rewards', $event));
        $this->assertSame(1, KisekiTransaction::count());
        $this->actingAs($this->character()->user)->get(route('nation-raid.rewards', $event))->assertOk()->assertDontSee('戦利品を選ぶ');
    }

    public function test_active_reward_table_shows_all_targets_and_frozen_policy_without_creating_rights(): void
    {
        $this->withoutMiddleware(CheckCharacterSelected::class);
        config()->set('features.nation_competitive_raid_enabled', true);
        [$event, $character] = $this->scenario();
        $event->update(['status' => 'active', 'stage10_reached_at' => null, 'completed_at' => null]);
        $event->battleResults()->latest('id')->first()->update(['status' => 'refunded']);
        config()->set('nation_raid.qualification.minimum_resolved_sorties', 35);
        config()->set('nation_raid_rewards.bottles.participation', 99);
        config()->set('nation_raid_rewards.damage_thresholds.damage250k', 900_000);

        $response = $this->actingAs($character->user)->get(route('nation-raid.rewards', $event));
        $response->assertOk()->assertSee('報酬一覧')->assertSee('条件未達')->assertSee('250,000')
            ->assertSee('14 / 15')->assertSee('2,100,000')->assertSee('探索力の小瓶 ×3')
            ->assertSee('イベント終了後の戦果確定')->assertDontSee('探索力の小瓶 ×99')
            ->assertDontSee('data-raid-claim-button', false)->assertDontSee('name="selection"', false);
        $this->assertCount(9, $response->viewData('rewardScreen')['rows']);
        foreach ($response->viewData('rewardScreen')['rows'] as $row) {
            $this->assertSame('unmet', $row['state']);
        }
        $this->assertDatabaseCount('nation_raid_personal_rewards', 0);
        $this->assertDatabaseCount('character_consumable_items', 0);
        $this->assertDatabaseCount('kiseki_transactions', 0);
        $this->assertSame('active', $event->fresh()->status);
    }

    #[DataProvider('unfinalizedRewardStates')]
    public function test_met_targets_wait_for_finalization_and_never_offer_early_claims(string $status): void
    {
        $this->withoutMiddleware(CheckCharacterSelected::class);
        config()->set('features.nation_competitive_raid_enabled', true);
        [$event, $character] = $this->scenario();
        $event->update(['status' => $status]);
        $response = $this->actingAs($character->user)->get(route('nation-raid.rewards', $event));
        $response->assertOk()->assertSee('確定待ち')->assertDontSee('data-raid-claim-button', false);
        $rows = collect($response->viewData('rewardScreen')['rows'])->keyBy('key');
        $this->assertSame('awaiting', $rows['participation']['state']);
        $this->assertSame('awaiting', $rows['completion']['state']);
        $this->assertSame('unmet', $rows['personal_top3']['state']);
        $this->assertDatabaseCount('nation_raid_personal_rewards', 0);
    }

    public static function unfinalizedRewardStates(): array
    {
        return [['active'], ['finalizing']];
    }

    public function test_reward_progress_uses_inclusive_thresholds_and_allows_only_one_cumulative_rank_honor(): void
    {
        [$event, $character] = $this->scenario();
        $standings = app(NationRaidRankingService::class)->standings($event);
        $screens = app(\App\Services\Nation\Raid\NationRaidRewardScreenService::class);
        foreach ($event->reward_policy_snapshot['damage_thresholds'] as $key => $threshold) {
            foreach ([-1 => 'unmet', 0 => 'awaiting'] as $offset => $expected) {
                $standings['personal_total'][0]['damage'] = $threshold + $offset;
                $rows = collect($screens->build($event, $character, $standings, collect())['rows'])->keyBy('key');
                $this->assertSame($expected, $rows[$key]['state'], $key.' at offset '.$offset);
            }
        }
        foreach ([1, 2, 3, 4] as $rank) {
            $standings['personal_total'][0]['rank'] = $rank;
            $rows = collect($screens->build($event, $character, $standings, collect())['rows'])->keyBy('key');
            $this->assertSame($rank === 1 ? 'awaiting' : 'unmet', $rows['personal_first']['state']);
            $this->assertSame(in_array($rank, [2, 3], true) ? 'awaiting' : 'unmet', $rows['personal_top3']['state']);
        }
    }

    public function test_reward_table_matches_real_entitlements_and_hides_claim_button_after_receipt(): void
    {
        $this->withoutMiddleware(CheckCharacterSelected::class);
        $this->withoutMiddleware(PreventRequestForgery::class);
        config()->set('features.nation_competitive_raid_enabled', true);
        [$event, $character] = $this->scenario();
        $this->actingAs($character->user);
        $preview = collect($this->get(route('nation-raid.rewards', $event))->viewData('rewardScreen')['rows'])->keyBy('key');
        app(NationRaidEventService::class)->completeFinalization($event);
        $response = $this->get(route('nation-raid.rewards', $event));
        $response->assertOk()->assertSee('data-raid-claim-button', false)->assertSee('>入手</button>', false);
        $rows = collect($response->viewData('rewardScreen')['rows'])->keyBy('key');
        $this->assertSame(8, $rows->where('state', 'claimable')->count());
        $dom = new \DOMDocument();
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $buttons = $xpath->query('//button[@data-raid-claim-button]');
        $this->assertCount(8, $buttons);
        foreach ($buttons as $button) {
            $form = $dom->getElementById($button->getAttribute('form'));
            $this->assertNotNull($form);
            $this->assertSame('POST', $form->getAttribute('method'));
            $this->assertSame(1, $xpath->query('.//input[@name="_token"]', $form)->length);
        }
        $this->assertSame(2, $xpath->query('//select[@name="selection" and @required]')->length);
        foreach (NationRaidPersonalReward::where('event_id', $event->id)->get() as $reward) {
            $row = $rows[$reward->reward_key];
            $this->assertSame($reward->id, $row['reward_id']);
            $this->assertSame($reward->reward_snapshot, $row['payload']);
            $this->assertSame($preview[$reward->reward_key]['contents'], $row['contents']);
            $this->assertSame('awaiting', $preview[$reward->reward_key]['state']);
        }
        $reward = $this->reward($event, 'participation');
        $url = route('nation-raid.rewards.claim', ['event' => $event, 'reward' => $reward->id]);
        $this->post($url)->assertRedirect(route('nation-raid.rewards', $event));
        $response = $this->get(route('nation-raid.rewards', $event));
        $response->assertOk()->assertSee('受取済み')->assertDontSee('action="'.$url.'"', false);
        $rows = collect($response->viewData('rewardScreen')['rows'])->keyBy('key');
        $this->assertSame('claimed', $rows['participation']['state']);
        $this->assertSame(7, $rows->where('state', 'claimable')->count());
        $this->post($url)->assertRedirect(route('nation-raid.rewards', $event));
        $this->assertDatabaseHas('character_consumable_items', ['character_id' => $character->id,
            'item_key' => 'explore_stamina_small_bottle', 'quantity' => 3]);
    }

    public function test_reward_table_does_not_offer_claims_to_nonparticipants_or_restricted_characters(): void
    {
        $this->withoutMiddleware(CheckCharacterSelected::class);
        config()->set('features.nation_competitive_raid_enabled', true);
        [$event, $character] = $this->scenario();
        app(NationRaidEventService::class)->completeFinalization($event);
        $outsider = $this->character();
        $response = $this->actingAs($outsider->user)->get(route('nation-raid.rewards', $event));
        $response->assertOk()->assertSee('0 / 15')->assertSee('条件未達')
            ->assertDontSee('data-raid-claim-button', false)->assertDontSee('name="selection"', false);
        $this->assertSame(9, collect($response->viewData('rewardScreen')['rows'])->where('state', 'unmet')->count());
        $character->update(['is_frozen' => true]);
        $screen = app(\App\Services\Nation\Raid\NationRaidRewardScreenService::class)->build(
            $event->fresh(), $character->fresh(), app(NationRaidRankingService::class)->standings($event->fresh()),
            NationRaidPersonalReward::where('event_id', $event->id)->get());
        $this->assertSame(0, collect($screen['rows'])->where('state', 'claimable')->count());
        $this->assertSame(8, NationRaidPersonalReward::where('status', 'pending')->count());
    }

    public function test_reward_table_fails_closed_for_an_entitlement_with_a_mismatched_policy(): void
    {
        $this->withoutMiddleware(CheckCharacterSelected::class);
        config()->set('features.nation_competitive_raid_enabled', true);
        [$event, $character] = $this->scenario();
        app(NationRaidEventService::class)->completeFinalization($event);
        $reward = $this->reward($event, 'participation');
        $reward->update(['reward_snapshot' => [...$reward->reward_snapshot, 'policy_hash' => str_repeat('0', 64)]]);
        $response = $this->actingAs($character->user)->get(route('nation-raid.rewards', $event));
        $response->assertOk()->assertDontSee('action="'.route('nation-raid.rewards.claim', ['event' => $event, 'reward' => $reward->id]).'"', false);
        $row = collect($response->viewData('rewardScreen')['rows'])->firstWhere('key', 'participation');
        $this->assertSame('unavailable', $row['state']);
        $this->assertSame('pending', $reward->fresh()->status);
    }

    public function test_honor_presentation_is_off_gated_and_nation_flag_expires_on_next_completed_event(): void
    {
        [$event, $character, $nation] = $this->scenario();
        app(NationRaidEventService::class)->completeFinalization($event);
        $honors = app(NationRaidHonorService::class);
        config()->set('features.nation_competitive_raid_enabled', false);
        $this->assertNull($honors->forNation($nation));
        config()->set('features.nation_competitive_raid_enabled', true);
        $this->assertSame('黒天竜討旗・金', $honors->forNation($nation)['label']);
        app(NationRaidRewardService::class)->claim($event, $character, $this->reward($event, 'personal_first')->id);
        $this->assertSame('万軍の先鋒', $honors->forCharacter($character)[0]['label']);
        $this->assertSame(['label', 'badge', 'event', 'date'], array_keys($honors->forCharacter($character)[0]));
        [$next] = $this->scenario();
        app(NationRaidEventService::class)->completeFinalization($next);
        $this->assertNull($honors->forNation($nation));
        $this->assertSame(3, DB::table('nation_activity_logs')->where('nation_id', $nation->id)->where('event_type', 'raid_reward')->count());
    }

    public function test_finalize_command_requires_confirmation_and_web_finalization_is_rejected(): void
    {
        [$event] = $this->scenario();
        $this->artisan('nation-raid:finalize', ['event' => $event->id])->assertFailed();
        $this->assertSame('finalizing', $event->fresh()->status);
        $admin = User::factory()->create(['role' => 'admin']);
        $operations = app(NationRaidOperationsService::class);
        $this->reject(fn () => $operations->operate($admin, $event->id, 999, 'finalize'), '運営CLI');
        $this->reject(fn () => $operations->operate($admin, $event->id, $event->state_version, 'finalize'), '運営CLI');
        $this->reject(fn () => $operations->operate(User::factory()->create(), $event->id, $event->state_version, 'finalize'), '管理者');
        $this->artisan('nation-raid:finalize', ['event' => $event->id, '--confirm-rewards' => true])->assertSuccessful();
        $this->artisan('nation-raid:finalize', ['event' => $event->id, '--confirm-rewards' => true])->assertSuccessful();
        $this->assertSame(8, NationRaidPersonalReward::where('event_id', $event->id)->count());
    }

    public function test_finalization_batches_personal_rights_and_freezes_unqualified_history_too(): void
    {
        [$event, $character] = $this->scenario();
        $other = $this->character();
        $this->addPlayer($event, $other, null);
        NationRaidBattleResult::where('event_id', $event->id)->where('character_id', $other->id)->where('event_sortie_no', '>', 1)->update(['status' => 'refunded']);
        DB::enableQueryLog();
        $completed = app(NationRaidEventService::class)->completeFinalization($event);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $inserts = array_filter($queries, fn ($query) => str_starts_with(strtolower($query['query']), 'insert into "nation_raid_personal_rewards"'));
        $this->assertCount(1, $inserts);
        $otherParticipation = $event->participations()->where('character_id', $other->id)->sole();
        $record = app(\App\Services\Nation\Raid\NationRaidFinalResultService::class)->forParticipant($completed, $otherParticipation);
        $this->assertFalse($record['qualified']);
        $this->assertSame(1, $record['resolved_sorties']);
        $this->assertSame(0, NationRaidPersonalReward::where('character_id', $other->id)->count());
    }

    #[DataProvider('finalizationFailureCases')]
    public function test_failed_finalization_logs_bounded_metrics_and_restores_the_connection(string $kind): void
    {
        [$event] = $this->scenario();
        $private = 'PRIVATE_SQL_BINDING_AND_MESSAGE';
        $pdo = new \PDOException($private);
        $pdo->errorInfo = ['08S01', 1153, $private];
        $exception = match ($kind) {
            'query' => new QueryException('sqlite', 'insert into private_table values (?)', [$private], $pdo),
            'wrapped_pdo' => new \RuntimeException($private, 0, $pdo),
            default => new \DomainException('確定を停止しました。'),
        };
        $this->mock(CharacterNotificationService::class)->shouldReceive('create')->once()->andThrow($exception);
        Log::spy();
        $connection = DB::connection();
        $dispatcher = $connection->getEventDispatcher();
        $level = $connection->transactionLevel();

        $this->artisan('nation-raid:finalize', ['event' => $event->id, '--confirm-rewards' => true])
            ->doesntExpectOutputToContain($private)->assertFailed();

        Log::shouldHaveReceived('error')->once()->with('Nation raid finalization', \Mockery::on(function (array $data) use ($event, $exception, $kind, $private): bool {
            $this->assertSame($event->id, $data['event_id']);
            $this->assertSame('console', $data['source']);
            $this->assertSame('failed', $data['result']);
            $this->assertSame($exception::class, $data['error_class']);
            $this->assertGreaterThanOrEqual(0, $data['elapsed_ms']);
            $this->assertGreaterThan(0, $data['query_count']);
            $this->assertGreaterThan(0, $data['process_peak_memory_bytes']);
            $this->assertSame($kind === 'domain' ? null : '08S01', $data['sqlstate']);
            $this->assertSame($kind === 'domain' ? null : 1153, $data['native_code']);
            $this->assertArrayNotHasKey('snapshot_bytes', $data);
            $this->assertStringNotContainsString($private, json_encode($data, JSON_THROW_ON_ERROR));
            $this->assertStringNotContainsString('private_table', json_encode($data, JSON_THROW_ON_ERROR));

            return true;
        }));
        Log::shouldNotHaveReceived('notice');
        $this->assertSame($dispatcher, $connection->getEventDispatcher());
        $this->assertSame($level, $connection->transactionLevel());
        $this->assertSame('finalizing', $event->fresh()->status);
        $this->assertNull($event->fresh()->final_standings_snapshot);
        $this->assertNull($event->participations()->sole()->final_result_snapshot);
        $this->assertDatabaseCount('nation_raid_personal_rewards', 0);
        $this->assertDatabaseCount('character_notifications', 0);
    }

    public static function finalizationFailureCases(): array
    {
        return [['domain'], ['query'], ['wrapped_pdo']];
    }

    private function scenario(): array
    {
        $event = app(NationRaidEventService::class)->createDraft('rewards-'.bin2hex(random_bytes(6)), '国家対抗レイド', now()->subDays(8));
        $event->update(['status' => 'finalizing', 'activated_at' => $event->starts_at, 'stage10_reached_at' => now()->subDays(3), 'completed_at' => now()->subDays(2)]);
        DB::table('nation_raid_daily_lineage_snapshots')->where('event_id', $event->id)->update(['determined_at' => now()]);
        $character = $this->character();
        $nation = Nation::create(['name' => '報酬国'.bin2hex(random_bytes(3)), 'nation_type' => 'kingdom', 'status' => 'active', 'founded_at' => now(), 'development_exp' => 0, 'treasury_points' => 0]);
        $this->addPlayer($event, $character, $nation);

        return [$event->fresh(), $character, $nation];
    }

    private function addPlayer(NationRaidEvent $event, Character $character, ?Nation $nation): void
    {
        $p = NationRaidParticipation::create(['event_id' => $event->id, 'account_id' => $character->user_id,
            'character_id' => $character->id, 'character_id_snapshot' => $character->id,
            'nation_id' => $nation?->id, 'nation_id_snapshot' => $nation?->id,
            'is_nation_eligible' => $nation !== null, 'reference_active_count' => $nation ? 1 : 0,
            'character_name_snapshot' => $character->name, 'nation_name_snapshot' => $nation?->name]);
        foreach (range(1, 15) as $i) {
            NationRaidBattleResult::create(['event_id' => $event->id, 'participation_id' => $p->id, 'account_id' => $p->account_id,
                'character_id' => $character->id, 'nation_id' => $nation?->id, 'battle_token' => bin2hex(random_bytes(32)),
                'sortie_seed' => str_repeat('a', 64), 'status' => 'resolved', 'raid_day' => 1, 'day_sortie_no' => $i, 'event_sortie_no' => $i,
                'target_cycle_no' => 1, 'target_cycle_kind' => 'main', 'target_stage_no' => 1, 'target_form' => 'sealed_scale',
                'target_parameter_snapshot' => [], 'boss_species_key' => 'dragon', 'strategy' => 'assault',
                'applied_damage_total' => 150_000, 'coordination_damage_total' => 0, 'nation_damage_total' => $nation ? 150_000 : 0,
                'max_action_damage' => 20_000, 'started_at' => $event->starts_at, 'resolved_at' => $event->starts_at,
                'resolution_deadline_at' => $event->starts_at->copy()->addMinutes(10)]);
        }
    }

    private function reward(NationRaidEvent $event, string $key): NationRaidPersonalReward
    {
        return NationRaidPersonalReward::where('event_id', $event->id)->where('reward_key', $key)->firstOrFail();
    }

    private function character(): Character
    {
        return Character::create(['user_id' => User::factory()->create()->id, 'name' => '報酬冒険者'.bin2hex(random_bytes(3)),
            'free_kiseki' => 0, 'paid_kiseki' => 7, 'kiseki' => 7]);
    }

    private function reject(callable $action, string $message): void
    {
        try {
            $action();
            $this->fail('Expected rejection');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }
}
