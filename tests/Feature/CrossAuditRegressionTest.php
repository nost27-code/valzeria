<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\City;
use App\Models\Enemy;
use App\Models\Item;
use App\Models\JobClass;
use App\Models\JobExpTable;
use App\Models\User;
use App\Services\BankService;
use App\Services\BattleService;
use App\Services\CharacterStatusService;
use App\Services\DropService;
use App\Services\EquipmentAutoUnequipService;
use App\Services\EquipmentMarketAppraisalService;
use App\Services\EquipmentMarketService;
use App\Services\EquipmentService;
use App\Services\ExplorationMapGenerator;
use App\Services\ExplorationMapGradeRewardService;
use App\Services\ExplorationStateService;
use App\Services\GameHealthCheckService;
use App\Services\InnService;
use App\Services\JobService;
use App\Services\LevelService;
use App\Services\MapExplorationBatchService;
use App\Services\MapExplorationRewardService;
use App\Services\MapPublicationService;
use App\Services\MapSurveyService;
use App\Services\PlayerLifecycleEventService;
use App\Services\ReleaseReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/** Cross-feature invariants reproduced by the September 25 audit. */
final class CrossAuditRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function character(array $attributes = []): Character
    {
        $character = Character::create(array_merge([
            'user_id' => User::factory()->create()->id,
            'name' => '横断監査用',
            'level' => 1, 'exp' => 0, 'current_job_id' => null,
            'hp_base' => 100, 'mp_base' => 100,
            'attack_base' => 100, 'defense_base' => 100,
            'magic_base' => 100, 'spirit_base' => 100,
            'speed_base' => 100, 'luck_base' => 100,
            'current_hp' => 20, 'current_mp' => 20,
            'money' => 1000, 'bank_gold' => 0, 'explore_stamina' => 0,
        ], $attributes));
        CharacterStatusService::clearRequestCache((int) $character->id);

        return $character;
    }

    private function accessory(Character $character): CharacterItem
    {
        $item = Item::create([
            'name' => '監査用装飾品', 'type' => 'accessory',
            'hp_bonus' => 100, 'mp_bonus' => 100,
            'accessory_performance_scale_version' => 2, 'is_active' => true,
        ]);

        return CharacterItem::create([
            'character_id' => $character->id, 'item_id' => $item->id,
            'is_equipped' => true, 'equipped_slot' => 'accessory',
        ]);
    }

    public function test_lodging_does_not_overwrite_a_bank_deposit_after_character_was_read(): void
    {
        $character = $this->character();
        $staleLodgingRequest = $character->fresh();
        app(BankService::class)->deposit($character, 900);
        $result = app(InnService::class)->rest($staleLodgingRequest);
        $this->assertTrue($result['success']);
        $actual = app(BankService::class)->summary($character->fresh());
        $this->assertSame(1000 - $result['paid'], $actual['total_gold'], json_encode($actual));
    }

    public function test_lodging_payment_and_recovery_roll_back_together(): void
    {
        $character = $this->character();
        $this->mock(ExplorationStateService::class)
            ->shouldReceive('reset')->once()->andThrow(new RuntimeException('audit failure injection'));
        try {
            app(InnService::class)->rest($character);
            $this->fail('Injected error was not reached');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit failure injection', $exception->getMessage());
        }
        $this->assertSame(1000, $character->fresh()->money);
        $this->assertSame(20, $character->fresh()->current_hp);
        $this->assertSame(20, $character->fresh()->current_mp);
    }

    public function test_unequip_clamps_sp_to_the_new_maximum_without_a_warm_cache(): void
    {
        $character = $this->character(['current_mp' => 200]);
        $item = $this->accessory($character);
        $result = app(EquipmentService::class)->unequip($character, $item);
        $this->assertTrue($result['success']);
        CharacterStatusService::clearRequestCache((int) $character->id);
        $max = app(CharacterStatusService::class)->getFinalStats($character)['max_mp'];
        $this->assertLessThanOrEqual($max, $character->fresh()->current_mp);
    }

    public function test_unequip_invalidates_previously_computed_stats(): void
    {
        $character = $this->character();
        $item = $this->accessory($character);
        $status = app(CharacterStatusService::class);
        $oldMax = $status->getFinalStats($character)['max_hp'];
        $character->update(['current_hp' => $oldMax]);
        $this->assertTrue(app(EquipmentService::class)->unequip($character, $item)['success']);
        $cachedMax = $status->getFinalStats($character)['max_hp'];
        CharacterStatusService::clearRequestCache((int) $character->id);
        $freshMax = $status->getFinalStats($character)['max_hp'];
        $this->assertSame($freshMax, $cachedMax, 'HP remaining='.$character->fresh()->current_hp);
    }

    public function test_level_up_updates_the_cached_stats_for_the_next_battle(): void
    {
        $character = $this->character();
        $status = app(CharacterStatusService::class);
        $old = $status->getFinalStats($character);
        app(LevelService::class)->addRewardAndCheckLevelUp($character, 50, 0);
        $this->assertSame(2, (int) $character->level);
        $cached = $status->getFinalStats($character);
        CharacterStatusService::clearRequestCache((int) $character->id);
        $fresh = $status->getFinalStats($character);
        $this->assertSame($fresh['max_hp'], $cached['max_hp'], 'old='.$old['max_hp']);
    }

    public function test_health_probe_preserves_the_selected_character_session(): void
    {
        $probeCharacter = $this->character();
        $viewerCharacter = $this->character();
        $this->actingAs($viewerCharacter->user);
        session(['current_character_id' => $viewerCharacter->id]);
        $method = new ReflectionMethod(GameHealthCheckService::class, 'withProbeUser');
        $method->invoke(app(GameHealthCheckService::class), $probeCharacter->user, static function (): void {
            Auth::user()->currentCharacter();
        });
        $this->assertSame($viewerCharacter->user_id, Auth::id());
        $this->assertSame($viewerCharacter->id, session('current_character_id'));
    }

    public function test_health_home_probe_renders_real_view(): void
    {
        $character = $this->character();
        $this->withoutVite();
        $method = new ReflectionMethod(GameHealthCheckService::class, 'probeMainScreen');
        $method->invoke(app(GameHealthCheckService::class), $character->user, 'home');
        $this->addToAssertionCount(1);
    }

    public function test_every_configured_extra_content_has_a_readiness_validator(): void
    {
        $unknown = [];
        foreach (array_keys(config('extra_content.contents')) as $key) {
            foreach (app(ReleaseReadinessService::class)->contentIssues($key) as $issue) {
                if (str_contains($issue, '未対応の追加コンテンツ')) {
                    $unknown[] = $issue;
                }
            }
        }
        $this->assertSame([], $unknown);
    }

    public function test_stale_equip_request_cannot_equip_an_item_transferred_to_another_owner(): void
    {
        $seller = $this->character();
        $buyer = $this->character();
        $this->accessory($buyer);
        $item = $this->accessory($seller);
        $item->update(['is_equipped' => false, 'equipped_slot' => null]);
        $staleRequestItem = $item->fresh();
        // Interleaving: the equipment route bound its model, then another
        // transaction completed the market ownership transfer before equip().
        $item->update(['character_id' => $buyer->id]);
        $result = app(EquipmentService::class)->equip($seller, $staleRequestItem);
        $count = $buyer->characterItems()->where('is_equipped', true)->where('equipped_slot', 'accessory')->count();
        $this->assertFalse($result['success'], 'buyer_equipped_accessories='.$count);
        $this->assertSame(1, $count);
        $this->assertSame($buyer->id, $item->fresh()->character_id);
    }

    public function test_equipping_the_same_item_twice_keeps_exactly_one_item_in_the_slot(): void
    {
        $character = $this->character();
        $item = $this->accessory($character);
        $equipment = app(EquipmentService::class);

        $this->assertTrue($equipment->equip($character, $item)['success']);
        $this->assertTrue($equipment->equip($character, $item)['success']);
        $this->assertTrue($item->fresh()->is_equipped);
        $this->assertSame(1, $character->characterItems()->where('equipped_slot', 'accessory')->count());
    }

    public function test_equipment_swap_clamps_both_resources_and_uses_current_character_values(): void
    {
        $character = $this->character(['current_hp' => 200, 'current_mp' => 200]);
        $old = $this->accessory($character);
        $replacement = $this->accessory($character);
        $replacement->update(['is_equipped' => false, 'equipped_slot' => null]);
        $replacement->item->update(['hp_bonus' => 25, 'mp_bonus' => 25]);
        $status = app(CharacterStatusService::class);
        $status->getFinalStats($character);

        $this->assertTrue(app(EquipmentService::class)->equip($character, $replacement)['success']);
        $stats = $status->getFinalStats($character);
        $this->assertFalse($old->fresh()->is_equipped);
        $this->assertSame($stats['max_hp'], $character->fresh()->current_hp);
        $this->assertSame($stats['max_mp'], $character->fresh()->current_mp);
        $this->assertLessThan(200, $stats['max_mp']);

        Character::whereKey($character->id)->update(['current_hp' => 5, 'money' => 15]);
        $this->assertTrue(app(EquipmentService::class)->equip($character, $replacement)['success']);
        $this->assertSame(5, $character->current_hp);
        $this->assertSame(15, $character->money);
    }

    public function test_failed_equipment_swap_restores_flags_resources_and_cached_stats(): void
    {
        $character = $this->character(['current_hp' => 200, 'current_mp' => 200]);
        $old = $this->accessory($character);
        $replacement = $this->accessory($character);
        $replacement->update(['is_equipped' => false, 'equipped_slot' => null]);
        $replacement->item->update(['hp_bonus' => 0, 'mp_bonus' => 0]);
        $before = app(CharacterStatusService::class)->getFinalStats($character);
        $this->mock(PlayerLifecycleEventService::class)->shouldReceive('recordFirstEquipmentChange')
            ->once()->andThrow(new RuntimeException('equipment failure injection'));

        $this->assertFalse(app(EquipmentService::class)->equip($character, $replacement)['success']);
        $this->assertTrue($old->fresh()->is_equipped);
        $this->assertFalse($replacement->fresh()->is_equipped);
        $this->assertSame(200, $character->fresh()->current_hp);
        $this->assertSame(200, $character->fresh()->current_mp);
        $this->assertSame($before, app(CharacterStatusService::class)->getFinalStats($character->fresh()));
    }

    public function test_job_rank_and_mastery_invalidate_cached_stats(): void
    {
        $job = JobClass::where('bonus_hp', '>', 0)->firstOrFail();
        $character = $this->character(['current_job_id' => $job->id]);
        $service = app(JobService::class);
        $status = app(CharacterStatusService::class);
        $before = $status->getFinalStats($character)['max_hp'];
        $rankExp = (int) (JobExpTable::where('job_level', 2)->value('required_exp') * $service->jobExpMultiplier($job->rank));

        $result = $service->addJobExp($character, $rankExp);
        $this->assertTrue($result['level_up']);
        $this->assertFalse($result['mastered']);
        $rankHp = $status->getFinalStats($character)['max_hp'];
        $this->assertGreaterThan($before, $rankHp);

        $masterExp = (int) (JobExpTable::where('job_level', 10)->value('required_exp') * $service->jobExpMultiplier($job->rank));
        $this->assertTrue($service->addJobExp($character, $masterExp)['mastered']);
        $this->assertGreaterThan($rankHp, $status->getFinalStats($character)['max_hp']);
    }

    public function test_automatic_unequip_invalidates_stats_before_clamping_resources(): void
    {
        $character = $this->character();
        $item = Item::create(['name' => '無効装備', 'type' => 'armor', 'hp_bonus' => 100,
            'mp_bonus' => 100, 'is_active' => false]);
        $owned = CharacterItem::create(['character_id' => $character->id, 'item_id' => $item->id,
            'is_equipped' => true, 'equipped_slot' => 'armor']);
        $status = app(CharacterStatusService::class);
        $before = $status->getFinalStats($character);
        $character->update(['current_hp' => $before['max_hp'], 'current_mp' => $before['max_mp']]);

        $this->assertCount(1, app(EquipmentAutoUnequipService::class)->unequipInvalidItems($character));
        $after = $status->getFinalStats($character);
        $this->assertFalse($owned->fresh()->is_equipped);
        $this->assertLessThan($before['max_hp'], $after['max_hp']);
        $this->assertSame($after['max_hp'], $character->fresh()->current_hp);
        $this->assertSame($after['max_mp'], $character->fresh()->current_mp);
    }

    public function test_market_listing_and_sale_reject_stale_equipment_requests_without_changing_gold(): void
    {
        $seller = $this->character(['money' => 100000]);
        $buyer = $this->character(['money' => 10000000]);
        $item = $this->marketWeapon($seller);
        $stale = $item->fresh();
        $market = app(EquipmentMarketService::class);
        $equipment = app(EquipmentService::class);
        $price = app(EquipmentMarketAppraisalService::class)->appraisal($item)['appraisal_price'];
        $listing = $market->listEquipment($seller, $item, $price);

        $this->assertFalse($equipment->equip($seller, $stale)['success']);
        $transaction = $market->buyEquipment($buyer, $listing);
        $this->assertTrue($equipment->equip($buyer, $item)['success']);
        $this->assertFalse($equipment->equip($seller, $stale)['success']);
        $this->assertFalse($equipment->unequip($seller, $stale)['success']);
        $this->assertSame($buyer->id, $item->fresh()->character_id);
        $this->assertTrue($item->fresh()->is_equipped);
        $this->assertSame(10000000 - $price, $buyer->fresh()->money);
        $this->assertSame(100000 + $transaction->seller_proceeds, $seller->fresh()->money);

        try {
            $market->buyEquipment($buyer, $listing);
            $this->fail('Sold listing was purchased twice');
        } catch (RuntimeException $exception) {
            $this->assertSame('この出品は購入できません。', $exception->getMessage());
        }
        $this->assertSame(10000000 - $price, $buyer->fresh()->money);
        $this->assertSame(1, DB::table('equipment_market_transactions')->count());
    }

    public function test_market_admin_cancellation_and_expiry_release_the_equipment(): void
    {
        $seller = $this->character();
        $market = app(EquipmentMarketService::class);
        foreach (['admin_cancelled', 'expired'] as $status) {
            $item = $this->marketWeapon($seller);
            $price = app(EquipmentMarketAppraisalService::class)->appraisal($item)['appraisal_price'];
            $listing = $market->listEquipment($seller, $item, $price);
            if ($status === 'admin_cancelled') {
                $market->adminCancelListing($listing);
            } else {
                $listing->update(['expires_at' => now()->subSecond()]);
                $market->expireListings();
            }
            $this->assertSame($status, $listing->fresh()->status);
            $this->assertNull($item->fresh()->market_listing_id);
            $this->assertTrue(app(EquipmentService::class)->equip($seller, $item)['success']);
        }
    }

    public function test_health_probe_restores_session_and_request_attribute_even_after_an_exception(): void
    {
        $probe = $this->character();
        $viewer = $this->character();
        $this->actingAs($viewer->user);
        $original = ['current_character_id' => $viewer->id, 'target_area_id' => null, 'target_area_purpose' => 'boss'];
        session($original);
        request()->attributes->set(GameHealthCheckService::REQUEST_ATTRIBUTE, 'original');
        $method = new ReflectionMethod(GameHealthCheckService::class, 'withProbeUser');
        try {
            $method->invoke(app(GameHealthCheckService::class), $probe->user, function () use ($probe): void {
                $this->assertSame($probe->id, Auth::user()->currentCharacter()->id);
                session(['target_area_id' => 123, 'target_area_purpose' => 'explore']);
                request()->attributes->set(GameHealthCheckService::REQUEST_ATTRIBUTE, 'home');
                throw new RuntimeException('probe failure injection');
            });
            $this->fail('Injected error was not reached');
        } catch (RuntimeException $exception) {
            $this->assertSame('probe failure injection', $exception->getMessage());
        }
        $this->assertSame($viewer->user_id, Auth::id());
        $this->assertSame($original, session()->only(array_keys($original)));
        $this->assertSame('original', request()->attributes->get(GameHealthCheckService::REQUEST_ATTRIBUTE));
    }

    public function test_health_dungeon_probe_leaves_an_anonymous_session_unchanged(): void
    {
        $probe = $this->character();
        session()->forget(['current_character_id', 'target_area_id', 'target_area_purpose']);
        $this->withoutVite();
        $method = new ReflectionMethod(GameHealthCheckService::class, 'probeMainScreen');
        $method->invoke(app(GameHealthCheckService::class), $probe->user, 'dungeon');
        $this->assertFalse(Auth::check());
        $this->assertSame([], session()->only(['current_character_id', 'target_area_id', 'target_area_purpose']));
        $this->assertFalse(request()->attributes->has(GameHealthCheckService::REQUEST_ATTRIBUTE));
    }

    public function test_equipment_book_validator_reports_a_missing_required_table(): void
    {
        Schema::drop('character_equipment_discoveries');
        $this->assertContains('必要テーブル character_equipment_discoveries がありません。',
            app(ReleaseReadinessService::class)->contentIssues('equipment_book'));
    }

    public function test_hero_trial_validator_detects_missing_area_job_and_mastery_requirement(): void
    {
        $key = array_key_first(config('hero_trials.released_trials'));
        $trial = config("hero_trials.released_trials.{$key}");
        config(['hero_trials.released_trials' => [$key => $trial]]);
        $service = app(ReleaseReadinessService::class);
        $this->assertSame([], $service->contentIssues('hero_trials'));

        config(["hero_trials.released_trials.{$key}.area_id" => 0]);
        $this->assertContains("英雄試練 {$key} の試練場マスタがありません。", $service->contentIssues('hero_trials'));
        config(["hero_trials.released_trials.{$key}.hero_job_key" => 'missing-job']);
        $this->assertContains("英雄試練 {$key} の職業マスタが不足しています。", $service->contentIssues('hero_trials'));

        config(['hero_trials.released_trials' => [$key => $trial]]);
        $heroJobId = JobClass::where('key', $trial['hero_job_key'])->value('id');
        $requiredJobId = JobClass::where('key', $trial['required_job_key'])->value('id');
        DB::table('job_requirements')->where('job_id', $heroJobId)->where('requirement_type', 'master_job')
            ->where('required_job_id', $requiredJobId)->delete();
        $this->assertContains("英雄試練 {$key} の必須職マスター条件がありません。", $service->contentIssues('hero_trials'));
    }

    private function marketWeapon(Character $owner): CharacterItem
    {
        $item = Item::create(['name' => '監査用市場剣', 'type' => 'weapon', 'weapon_category' => 'sword',
            'weapon_rank' => 'S', 'str_bonus' => 100, 'is_active' => true, 'is_tradeable' => true,
            'innate_killer_species_key' => 'dragon', 'innate_killer_damage_rate' => 0.12]);

        return CharacterItem::create(['character_id' => $owner->id, 'item_id' => $item->id,
            'is_equipped' => false, 'is_locked' => false, 'is_tradeable' => true]);
    }

    public function test_map_batch_uses_grown_stats_from_the_second_battle(): void
    {
        config(['exploration_maps.reward_profiles.ancient_fragment.weight' => 0]);
        $city = City::findOrFail(1);
        $area = Area::create([
            'name' => '監査用地図', 'slug' => 'cross-audit-map', 'city_id' => $city->id,
            'recommended_level_min' => 20, 'recommended_level_max' => 30,
        ]);
        $enemyAttributes = [
            'name' => '監査用魔物', 'area_id' => $area->id, 'level' => 45,
            'max_hp' => 100, 'str' => 20, 'def' => 10, 'agi' => 10,
            'mag' => 10, 'spr' => 10, 'luk' => 10, 'exp_reward' => 20,
            'gold_reward' => 10, 'job_exp_reward' => 1, 'appearance_weight' => 1, 'is_boss' => false,
        ];
        $enemy = Enemy::create($enemyAttributes);
        Enemy::create(array_merge($enemyAttributes, ['name' => '監査用古代魔物', 'level' => 160]));
        $owner = $this->character(['money' => 10000]);
        $map = app(ExplorationMapGenerator::class)->generate($owner, $area, $enemy, (string) Str::uuid());
        $registration = app(MapPublicationService::class)->publish(
            $owner, app(MapSurveyService::class)->start($owner, $map, $city), 0);
        $visitor = $this->character([
            'current_hp' => 10000, 'hp_base' => 10000, 'attack_base' => 1000000,
            'speed_base' => 1000000, 'money' => 10000,
        ]);
        $this->mock(MapExplorationRewardService::class)
            ->shouldReceive('rewardsFor')->twice()->andReturn(['experience' => 50, 'gold' => 0]);
        $this->mock(DropService::class)
            ->shouldReceive('rollBattleDrops')->twice()->andReturn(['materials' => [], 'equipment' => []]);
        $this->mock(ExplorationMapGradeRewardService::class)
            ->shouldReceive('tryDrop')->twice()->andReturn(['materials' => [], 'equipment' => []]);
        $actualBattleService = app(BattleService::class);
        $seen = [];
        $this->mock(BattleService::class)->shouldReceive('executeBattle')->twice()
            ->andReturnUsing(function ($character, $enemy, $bonus, ...$options) use ($actualBattleService, &$seen) {
                $seen[] = ['level' => (int) $character->level,
                    'max_hp' => app(CharacterStatusService::class)->getFinalStats($character)['max_hp']];

                return $actualBattleService->executeBattle($character, $enemy, $bonus, ...$options);
            });
        $service = app(MapExplorationBatchService::class);
        $batch = $service->reserve($visitor, $registration, 2, (string) Str::uuid());
        $service->execute($visitor, $batch);
        $this->assertSame(2, (int) $batch->fresh()->executed_count);
        $this->assertSame(2, $seen[1]['level']);
        $this->assertGreaterThan($seen[0]['max_hp'], $seen[1]['max_hp'], json_encode($seen));
    }
}
