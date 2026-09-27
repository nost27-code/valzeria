<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Character;
use App\Models\CharacterMaterial;
use App\Models\Enemy;
use App\Models\GameSetting;
use App\Models\Material;
use App\Models\PlayerValmon;
use App\Models\User;
use App\Models\ValmonMaster;
use App\Services\AreaService;
use App\Services\BankService;
use App\Services\Battle\BattleResult;
use App\Services\BattleService;
use App\Services\DropService;
use App\Services\ExplorationService;
use App\Services\ExplorationStateService;
use App\Services\GameSettingService;
use App\Services\MarketService;
use App\Services\PvPBattleService;
use App\Services\ValmonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PublicReadinessSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function actor(string $name = '試験者'): Character
    {
        return Character::create([
            'user_id' => User::factory()->create()->id, 'name' => $name,
            'level' => 1, 'exp' => 0, 'current_job_id' => null, 'hp_base' => 100, 'mp_base' => 100,
            'current_hp' => 100, 'current_mp' => 100, 'money' => 1000, 'bank_gold' => 0,
            'explore_stamina' => 100, 'explore_stamina_updated_at' => now(),
        ]);
    }

    private function area(): Area
    {
        GameSetting::updateOrCreate(['setting_key' => 'exploration.mode'], ['value' => 'stamina', 'value_type' => 'string']);
        app(GameSettingService::class)->flush();
        $area = Area::create(['name' => '試験場', 'slug' => 'safety-area', 'city_id' => 1, 'recommended_level_min' => 1, 'recommended_level_max' => 3]);
        Enemy::create(['name' => '試験敵', 'area_id' => $area->id, 'level' => 1, 'max_hp' => 1, 'str' => 1, 'def' => 1, 'agi' => 1, 'mag' => 1, 'spr' => 1, 'luk' => 1, 'exp_reward' => 1, 'gold_reward' => 10, 'job_exp_reward' => 0, 'appearance_weight' => 1, 'is_boss' => false]);

        return $area;
    }

    private function win(): BattleResult
    {
        $result = new BattleResult;
        $result->result = 'victory';
        $result->gold = 10;
        $result->playerHpAfter = 100;
        $result->playerMpAfter = 100;

        return $result;
    }

    private function grantStarter(Character $actor): void
    {
        $master = ValmonMaster::create(['valmon_key' => 'starter-'.$actor->id, 'name' => '試験相棒', 'rarity' => 'normal', 'is_active' => true]);
        PlayerValmon::create(['character_id' => $actor->id, 'valmon_master_id' => $master->id, 'is_partner' => false, 'obtained_at' => now()]);
    }

    public function test_battle_exception_rolls_back_stamina_and_timestamp(): void
    {
        $actor = $this->actor();
        $area = $this->area();
        $this->mock(BattleService::class)->shouldReceive('executeBattle')->once()->andThrow(new \RuntimeException('battle failed'));
        try {
            app(ExplorationService::class)->explore($actor, $area->id);
            $this->fail('Expected exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('battle failed', $e->getMessage());
        }
        $this->assertSame(100, (int) $actor->fresh()->explore_stamina);
        $this->assertNull($actor->fresh()->last_battle_at);
        $this->assertDatabaseCount('battle_logs', 0);
    }

    public function test_drop_exception_rolls_back_rewards_and_wins(): void
    {
        $actor = $this->actor();
        $area = $this->area();
        $this->mock(BattleService::class)->shouldReceive('executeBattle')->once()->andReturn($this->win());
        $this->mock(DropService::class)->shouldReceive('rollBattleDrops')->once()->andThrow(new \RuntimeException('drop failed'));
        try {
            app(ExplorationService::class)->explore($actor, $area->id);
            $this->fail('Expected exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('drop failed', $e->getMessage());
        }
        $this->assertSame(1000, (int) $actor->money);
        $this->assertSame(0, (int) $actor->wins);
        $this->assertSame(100, (int) $actor->explore_stamina);
        $this->assertDatabaseCount('battle_logs', 0);
    }

    public function test_exploration_uses_balance_after_an_intervening_deposit(): void
    {
        $actor = $this->actor();
        $area = $this->area();
        $stale = $actor->fresh();
        app(BankService::class)->deposit($actor, 900);
        $this->mock(BattleService::class)->shouldReceive('executeBattle')->once()->andReturn($this->win());
        app(ExplorationService::class)->explore($stale, $area->id);
        $this->assertSame(110, (int) $actor->fresh()->money);
        $this->assertSame(900, (int) $actor->fresh()->bank_gold);
    }

    public function test_forged_lord_challenge_does_not_consume_stamina(): void
    {
        $actor = $this->actor();
        $area = $this->area();
        app(ExplorationStateService::class)->getOrStart($actor, $area->id)->update(['dungeon_lord_encountered' => true]);
        $this->mock(BattleService::class)->shouldNotReceive('executeBattle');
        $this->assertArrayHasKey('error', app(ExplorationService::class)->explore($actor, $area->id, false, 'dungeon_lord'));
        $this->assertSame(100, (int) $actor->fresh()->explore_stamina);
    }

    public function test_lord_permission_is_single_use_and_bound_to_encounter(): void
    {
        $actor = $this->actor();
        $area = $this->area();
        $token = (string) Str::uuid();
        $state = app(ExplorationStateService::class)->getOrStart($actor, $area->id);
        $state->update(['exploration_point' => 300, 'dungeon_lord_encountered' => true, 'dungeon_lord_token' => $token]);
        $this->mock(BattleService::class)->shouldReceive('executeBattle')->once()->andReturn($this->win());
        $service = app(ExplorationService::class);
        $this->assertArrayHasKey('error', $service->challengeDungeonLord($actor, $area->id, (string) Str::uuid()));
        $this->assertTrue($service->challengeDungeonLord($actor, $area->id, $token)['success']);
        $this->assertNull($state->fresh()->dungeon_lord_token);
        $this->assertArrayHasKey('error', $service->challengeDungeonLord($actor, $area->id, $token));
        $this->assertSame(99, (int) $actor->fresh()->explore_stamina);
    }

    private function feedContext(): array
    {
        $actor = $this->actor();
        $master = ValmonMaster::create(['valmon_key' => 'safety-feed', 'name' => '試験相棒', 'rarity' => 'normal', 'is_active' => true]);
        $valmon = PlayerValmon::create(['character_id' => $actor->id, 'valmon_master_id' => $master->id, 'is_partner' => true, 'obtained_at' => now()]);
        $material = Material::create(['material_code' => 'SAFETY_FEED', 'name' => '試験素材', 'category' => '地域素材', 'rarity' => 'N', 'material_type' => 'city_material']);
        $owned = CharacterMaterial::create(['character_id' => $actor->id, 'material_id' => $material->id, 'quantity' => 3]);

        return [$actor, $valmon, $owned];
    }

    public function test_deleted_or_insufficient_material_cannot_grant_feed_exp(): void
    {
        [$actor, $valmon, $owned] = $this->feedContext();
        $stale = $owned->fresh()->load('material');
        $owned->update(['quantity' => 1]);
        $this->assertFalse(app(ValmonService::class)->feedMaterial($actor, $valmon, $stale, 3)['success']);
        $owned->delete();
        $this->assertFalse(app(ValmonService::class)->feedMaterial($actor, $valmon, $stale, 1)['success']);
        $this->assertSame(0, (int) $valmon->fresh()->exp);
        $this->assertDatabaseCount('valmon_feed_logs', 0);
    }

    public function test_repeated_feeding_uses_fresh_valmon_and_quantity(): void
    {
        [$actor, $valmon, $owned] = $this->feedContext();
        $service = app(ValmonService::class);
        $this->assertTrue($service->feedMaterial($actor, $valmon, $owned, 1)['success']);
        $this->assertTrue($service->feedMaterial($actor, $valmon, $owned, 2)['success']);
        $this->assertFalse($service->feedMaterial($actor, $valmon, $owned, 1)['success']);
        $this->assertSame(3, (int) $valmon->fresh()->exp);
        $this->assertDatabaseCount('valmon_feed_logs', 2);
    }

    public function test_exploration_post_replay_returns_saved_result_without_new_rewards(): void
    {
        $actor = $this->actor();
        $area = $this->area();
        $this->grantStarter($actor);
        $this->partialMock(AreaService::class, fn ($mock) => $mock->shouldReceive('canEnterArea')->andReturn(true));
        $this->mock(BattleService::class)->shouldReceive('executeBattle')->once()->andReturn($this->win());
        $this->actingAs($actor->user)->withSession(['current_character_id' => $actor->id]);
        $payload = ['exploration_request_id' => (string) Str::uuid(), 'continue_chain' => 1];
        $first = $this->post(route('battle.explore', $area), $payload)->assertRedirect();
        $url = $first->headers->get('Location');
        Cache::flush(); // cache・セッション結果を失ってもDBから同じ結果を読める。
        session()->forget(['battleData', 'lastBattleData']);
        $this->post(route('battle.explore', $area), $payload)->assertRedirect($url);
        $this->assertDatabaseCount('exploration_requests', 1);
        $this->assertDatabaseCount('battle_logs', 1);
        $this->assertSame(1010, (int) $actor->fresh()->money);
        $this->assertSame(99, (int) $actor->fresh()->explore_stamina);
        $this->get($url)->assertOk()->assertSee('試験敵');
        $this->post(route('battle.explore', $area), [...$payload, 'batch_count' => 10])->assertStatus(409);
        $other = $this->actor('別の冒険者');
        $this->grantStarter($other);
        $this->actingAs($other->user)->withSession(['current_character_id' => $other->id]);
        $this->get($url)->assertRedirect(route('home'));
        $this->actingAs($actor->user)->withSession(['current_character_id' => $actor->id]);
        DB::table('exploration_requests')->update(['created_at' => now()->subDays(2)]);
        $this->artisan('exploration:prune-results')->assertSuccessful();
        $this->assertNull(DB::table('exploration_requests')->value('battle_data'));
        $this->post(route('battle.explore', $area), $payload)->assertRedirect($url);
        $this->assertDatabaseCount('battle_logs', 1);
    }

    public function test_missing_operation_token_cannot_execute_battle(): void
    {
        $actor = $this->actor();
        $area = $this->area();
        $this->grantStarter($actor);
        $this->mock(BattleService::class)->shouldNotReceive('executeBattle');
        $this->actingAs($actor->user)->withSession(['current_character_id' => $actor->id])
            ->post(route('battle.explore', $area))->assertRedirect(route('home'))->assertSessionHas('error');
        $this->assertDatabaseCount('exploration_requests', 0);
    }

    public function test_busy_ajax_response_is_preserved_without_consuming_an_operation(): void
    {
        $actor = $this->actor();
        $area = $this->area();
        $this->grantStarter($actor);
        $this->partialMock(AreaService::class, fn ($mock) => $mock->shouldReceive('canEnterArea')->andReturn(true));
        $this->mock(BattleService::class)->shouldNotReceive('executeBattle');
        Cache::put('explore_request_delay:'.$actor->id, true, 60);
        $this->actingAs($actor->user)->withSession(['current_character_id' => $actor->id])
            ->post(route('battle.explore', $area), ['continue_chain' => 1, 'exploration_request_id' => (string) Str::uuid()], ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(409)->assertHeader('X-Explore-Busy', '1');
        $this->assertDatabaseCount('exploration_requests', 0);
        $this->assertSame(100, (int) $actor->fresh()->explore_stamina);
    }

    public function test_batch_exception_rolls_back_entire_request_and_same_token_can_retry(): void
    {
        $actor = $this->actor();
        $area = $this->area();
        $this->grantStarter($actor);
        $this->partialMock(AreaService::class, fn ($mock) => $mock->shouldReceive('canEnterArea')->andReturn(true));
        $calls = 0;
        $this->mock(BattleService::class)->shouldReceive('executeBattle')->times(4)->andReturnUsing(function () use (&$calls) {
            if (++$calls === 2) {
                throw new \RuntimeException('second battle failed');
            }

            return $this->win();
        });
        $this->withoutExceptionHandling()->actingAs($actor->user)->withSession(['current_character_id' => $actor->id]);
        $payload = ['exploration_request_id' => (string) Str::uuid(), 'batch_count' => 2, 'continue_chain' => 1];
        try {
            $this->post(route('battle.explore', $area), $payload);
            $this->fail('Expected exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('second battle failed', $e->getMessage());
        }
        $this->assertDatabaseCount('exploration_requests', 0);
        $this->assertDatabaseCount('battle_logs', 0);
        $this->assertSame(1000, (int) $actor->fresh()->money);
        $this->assertSame(100, (int) $actor->fresh()->explore_stamina);
        Cache::flush();
        $this->post(route('battle.explore', $area), $payload)->assertRedirect()->assertSessionHas('battleData.result.batch_explore.completed', 2);
        $this->assertDatabaseCount('battle_logs', 2);
        $this->assertDatabaseCount('exploration_requests', 1);
        $this->assertSame(1020, (int) $actor->fresh()->money);
    }

    public function test_partial_market_fill_cancel_and_expiry_do_not_duplicate_materials(): void
    {
        $seller = $this->actor('売り手');
        $buyer = $this->actor('買い手');
        $material = Material::create(['material_code' => 'SAFETY_MARKET', 'name' => '市場素材', 'category' => 'テスト', 'rarity' => 'N', 'is_tradable' => true,
            'trade_policy' => 'marketable', 'market_min_price' => 1, 'market_max_price' => 100, 'is_key_item' => false, 'is_cash_item' => false]);
        CharacterMaterial::create(['character_id' => $seller->id, 'material_id' => $material->id, 'quantity' => 5]);
        $market = app(MarketService::class);
        $first = $market->listMaterial($seller, $material, 3, 10);
        $second = $market->listMaterial($seller, $material, 2, 20);
        $market->buyMaterial($buyer, $material, 2);
        $this->assertSame(1, (int) $first->fresh()->remaining_quantity);
        $market->cancelListing($seller, $first);
        $second->update(['expires_at' => now()->subMinute()]);
        $this->assertSame(1, $market->expireListings());
        $this->assertSame(0, $market->expireListings());
        $this->assertSame(3, (int) CharacterMaterial::where('character_id', $seller->id)->where('material_id', $material->id)->value('quantity'));
        $this->assertSame(2, (int) CharacterMaterial::where('character_id', $buyer->id)->where('material_id', $material->id)->value('quantity'));
        try {
            $market->cancelListing($seller, $first);
            $this->fail('Already cancelled');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('listing', $e->errors());
        }
        $this->assertSame(5, (int) CharacterMaterial::where('material_id', $material->id)->sum('quantity'));
    }

    public function test_html_names_are_text_in_pve_and_pvp_without_escaping_log_markup(): void
    {
        $actor = $this->actor('<textarea>');
        $area = $this->area();
        $enemy = Enemy::where('area_id', $area->id)->firstOrFail();
        $result = app(BattleService::class)->executeBattle($actor, $enemy);
        $pve = implode('', $result->logs);
        $this->assertStringNotContainsString('<textarea>', $pve);
        $this->assertStringContainsString('&lt;textarea&gt;', $pve);
        $this->assertStringContainsString('<span ', $pve);
        $defender = $this->actor('<input>');
        $pvp = implode('', app(PvPBattleService::class)->resolveBattle($actor->fresh(), $defender)->result->logs);
        $this->assertStringNotContainsString('<textarea>', $pvp);
        $this->assertStringNotContainsString('<input>', $pvp);
        $this->assertStringContainsString('&lt;input&gt;', $pvp);
        $this->assertStringNotContainsString('&amp;lt;', $pvp);
    }
}
