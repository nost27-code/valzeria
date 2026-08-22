<?php

namespace Tests\Feature;

use App\Http\Controllers\BattleController;
use App\Models\Area;
use App\Models\Character;
use App\Models\CharacterSubAreaRouteDiscovery;
use App\Models\SubArea;
use App\Models\SubAreaRoute;
use App\Models\User;
use App\Services\AreaService;
use App\Services\BattleLogService;
use App\Services\BattleService;
use App\Services\CharacterStatusService;
use App\Services\DiscoveryService;
use App\Services\DropService;
use App\Services\ExplorationService;
use App\Services\ExplorationStaminaService;
use App\Services\KisekiDropService;
use App\Services\LevelService;
use App\Services\PublicLogService;
use App\Services\RegionDepthDungeonService;
use App\Services\SubAreaExplorationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class ExplorationRepeatServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_repeat_count_accepts_only_integers_from_one_to_fifty(): void
    {
        $this->assertSame([1, 10], ExplorationService::QUICK_REPEAT_COUNTS);
        $this->assertSame(1, ExplorationService::MIN_REPEAT_COUNT);
        $this->assertSame(2, ExplorationService::MIN_CUSTOM_REPEAT_COUNT);
        $this->assertSame(50, ExplorationService::DEFAULT_CUSTOM_REPEAT_COUNT);
        $this->assertSame(50, ExplorationService::MAX_REPEAT_COUNT);
        $this->assertSame(1, ExplorationService::normalizeRepeatCount(0));
        $this->assertSame(20, ExplorationService::normalizeRepeatCount(20));
        $this->assertSame(25, ExplorationService::normalizeRepeatCount('25'));
        $this->assertSame(30, ExplorationService::normalizeRepeatCount(30));
        $this->assertSame(10, ExplorationService::normalizeRepeatCount('10'));
        $this->assertSame(50, ExplorationService::normalizeRepeatCount(50));
        $this->assertSame(1, ExplorationService::normalizeRepeatCount(51));
        $this->assertSame(1, ExplorationService::normalizeRepeatCount('25.5'));
        $this->assertSame(1, ExplorationService::normalizeRepeatCount('invalid'));
        $this->assertSame(50, ExplorationService::staminaCostForCount(2, 25));
    }

    public function test_fifty_run_exploration_does_not_start_without_the_full_stamina_cost(): void
    {
        $character = $this->characterWithStamina(49);
        $service = $this->fakeExplorationService();

        $result = $service->exploreRepeated($character, 999, 50);

        $this->assertSame('50回探索には探索力が50必要です。', $result['error']);
        $this->assertSame(50, data_get($result, 'batch_explore.requested'));
        $this->assertSame(0, data_get($result, 'batch_explore.completed'));
        $this->assertSame('stamina_shortage', data_get($result, 'batch_explore.stop_reason'));
        $this->assertSame(0, $service->exploreCalls);
        $this->assertSame(49, $character->fresh()->explore_stamina);
    }

    public function test_fifty_run_exploration_can_complete_all_fifty_runs(): void
    {
        $character = $this->characterWithStamina(50);
        $service = $this->fakeExplorationService();

        $result = $service->exploreRepeated($character, 999, 50);

        $this->assertSame(50, data_get($result, 'batch_explore.requested'));
        $this->assertSame(50, data_get($result, 'batch_explore.completed'));
        $this->assertNull(data_get($result, 'batch_explore.stop_reason'));
        $this->assertCount(50, data_get($result, 'batch_explore.runs'));
        $this->assertSame(50, $service->exploreCalls);
        $this->assertSame(0, $character->fresh()->explore_stamina);
        $this->assertStringContainsString('【50回探索】最大50回', (string) $result['log']);
    }

    public function test_repeat_exploration_can_use_the_sub_area_runner_without_stopping_on_its_marker(): void
    {
        $character = $this->characterWithStamina(3);
        $service = $this->fakeExplorationService();
        $subAreaCalls = 0;

        $result = $service->exploreRepeated(
            $character,
            999,
            3,
            static function (Character $currentCharacter) use (&$subAreaCalls): array {
                $subAreaCalls++;
                $currentCharacter->explore_stamina = max(0, (int) $currentCharacter->explore_stamina - 1);
                $currentCharacter->save();

                return [
                    'result' => 'victory',
                    'enemy' => (object) ['name' => '亜域の試験敵'],
                    'log' => '',
                    'exp_gained' => 1,
                    'gold_gained' => 1,
                    'job_exp_gained' => 1,
                    'level_up_details' => [],
                    'material_drop' => [],
                    'equipment_drops' => [],
                    'new_discoveries' => [],
                    'special_event' => 'sub_area_explore',
                ];
            },
            ['sub_area_explore'],
        );

        $this->assertSame(3, $subAreaCalls);
        $this->assertSame(0, $service->exploreCalls);
        $this->assertSame(3, data_get($result, 'batch_explore.completed'));
        $this->assertNull(data_get($result, 'batch_explore.stop_reason'));
        $this->assertSame('sub_area_explore', $result['special_event']);
        $this->assertSame(0, $character->fresh()->explore_stamina);
    }

    public function test_fifty_run_exploration_stops_on_the_defeat_run(): void
    {
        $character = $this->characterWithStamina(50);
        $service = $this->fakeExplorationService(defeatAt: 3);

        $result = $service->exploreRepeated($character, 999, 50);

        $this->assertSame(50, data_get($result, 'batch_explore.requested'));
        $this->assertSame(3, data_get($result, 'batch_explore.completed'));
        $this->assertSame('defeat', data_get($result, 'batch_explore.stop_reason'));
        $this->assertCount(3, data_get($result, 'batch_explore.runs'));
        $this->assertSame(3, $service->exploreCalls);
        $this->assertSame(47, $character->fresh()->explore_stamina);
        $this->assertStringContainsString('3回目の試験敵戦で敗北したため', (string) data_get($result, 'batch_explore.stop_text'));
    }

    public function test_repeat_exploration_reports_timeout_as_an_explicit_defeat_with_turns_and_loss(): void
    {
        $character = $this->characterWithStamina(10);
        $service = $this->fakeExplorationService(timeoutAt: 3);

        $result = $service->exploreRepeated($character, 999, 10);

        $this->assertSame('timeout', data_get($result, 'batch_explore.stop_reason'));
        $this->assertSame(3, data_get($result, 'batch_explore.completed'));
        $this->assertSame(50, data_get($result, 'batch_explore.runs.2.turn_count'));
        $this->assertSame(
            '3回目の試験敵戦は50ターンで決着せず、時間切れ敗北となったため探索を終了しました。HPは敗北後に最大HPの30%まで回復した状態です。',
            data_get($result, 'batch_explore.stop_text'),
        );
        $this->assertSame(100, data_get($result, 'batch_explore.defeat_loss.gold_amount'));
        $this->assertStringContainsString('時間切れ敗北（50ターン）', (string) $result['log']);
    }

    public function test_returning_with_loot_resets_the_selected_count_to_one_for_the_next_exploration(): void
    {
        $character = $this->characterWithStamina(50);
        $sessionKey = 'exploration_selected_count.' . $character->id;

        $response = $this->withoutMiddleware()
            ->actingAs($character->user)
            ->withSession([
                'current_character_id' => $character->id,
                $sessionKey => 25,
            ])
            ->post(route('battle.return'));

        $response->assertRedirect(route('home', ['skip_resume' => 1]));
        $response->assertSessionMissing($sessionKey);
        $response->assertSessionHas('current_location', 'town');
    }

    public function test_selected_count_is_reused_by_a_continuing_exploration_request(): void
    {
        $character = $this->characterWithStamina(50);
        $controller = app(BattleController::class);
        $resolve = new ReflectionMethod($controller, 'resolveExploreCount');
        $this->app['session']->start();

        $selected = $resolve->invoke(
            $controller,
            Request::create('/battle/areas/999/explore', 'POST', ['batch_count' => 25]),
            $character,
        );
        $continued = $resolve->invoke(
            $controller,
            Request::create('/battle/areas/999/explore', 'POST', ['continue_chain' => 1]),
            $character,
        );

        $this->assertSame(25, $selected);
        $this->assertSame(25, $continued);
        $this->assertSame(25, session('exploration_selected_count.' . $character->id));
    }

    public function test_continuation_without_an_explicit_count_runs_one_battle_and_keeps_the_selected_count(): void
    {
        $character = $this->characterWithStamina(50);
        $character->forceFill(['current_hp' => 30])->save();
        $area = Area::query()->create([
            'name' => '探索継続の試験場',
            'slug' => 'single-continuation-test',
        ]);
        $sessionKey = 'exploration_selected_count.' . $character->id;
        $service = $this->fakeExplorationService();
        $this->app->instance(ExplorationService::class, $service);
        $this->allowNormalAreaExploration();

        $response = $this->withoutMiddleware()
            ->actingAs($character->user)
            ->withSession([
                'current_character_id' => $character->id,
                $sessionKey => 50,
            ])
            ->post(route('battle.explore', ['area' => $area->id]), [
                'continue_chain' => 1,
            ]);

        $response->assertRedirect(route('battle.result'));
        $response->assertSessionHas('battleData.selectedExploreCount', 50);
        $response->assertSessionHas($sessionKey, 50);
        $this->assertSame(1, $service->exploreCalls);
        $this->assertSame(49, (int) $character->fresh()->explore_stamina);
    }

    public function test_continuation_with_an_explicit_repeat_count_still_uses_batch_exploration(): void
    {
        $character = $this->characterWithStamina(50);
        $area = Area::query()->create([
            'name' => '明示回数探索の試験場',
            'slug' => 'explicit-repeat-continuation-test',
        ]);
        $sessionKey = 'exploration_selected_count.' . $character->id;
        $service = $this->fakeExplorationService();
        $this->app->instance(ExplorationService::class, $service);
        $this->allowNormalAreaExploration();

        $response = $this->withoutMiddleware()
            ->actingAs($character->user)
            ->withSession([
                'current_character_id' => $character->id,
                $sessionKey => 10,
            ])
            ->post(route('battle.explore', ['area' => $area->id]), [
                'continue_chain' => 1,
                'batch_count' => 50,
            ]);

        $response->assertRedirect(route('battle.result'));
        $response->assertSessionHas('battleData.selectedExploreCount', 50);
        $response->assertSessionHas($sessionKey, 50);
        $this->assertSame(50, $service->exploreCalls);
        $this->assertSame(0, (int) $character->fresh()->explore_stamina);
    }

    public function test_sub_area_request_with_an_explicit_count_uses_the_shared_repeat_path(): void
    {
        $character = $this->characterWithStamina(50);
        $area = Area::query()->create([
            'name' => '亜域連続探索の入口',
            'slug' => 'sub-area-repeat-entry-test',
        ]);
        $subArea = SubArea::query()->create([
            'name' => '亜域連続探索の試験場',
            'is_enabled' => true,
        ]);
        $route = SubAreaRoute::query()->create([
            'sub_area_id' => $subArea->id,
            'source_area_id' => $area->id,
            'route_name' => '連続探索試験路',
            'is_enabled' => true,
        ]);
        $discovery = CharacterSubAreaRouteDiscovery::query()->create([
            'character_id' => $character->id,
            'sub_area_route_id' => $route->id,
            'discovered_at' => now(),
        ]);

        $staminaService = Mockery::mock(ExplorationStaminaService::class);
        $staminaService->shouldReceive('enabled')->once()->andReturnTrue();
        $this->app->instance(ExplorationStaminaService::class, $staminaService);

        $subAreaService = Mockery::mock(SubAreaExplorationService::class);
        $subAreaService->shouldReceive('explore')->never();
        $subAreaService->shouldReceive('exploreRepeated')
            ->once()
            ->withArgs(fn (Character $actualCharacter, CharacterSubAreaRouteDiscovery $actualDiscovery, int $count): bool =>
                (int) $actualCharacter->id === (int) $character->id
                && (int) $actualDiscovery->id === (int) $discovery->id
                && $count === 10)
            ->andReturn([
                'result' => 'victory',
                'enemy' => (object) ['name' => '亜域の試験敵'],
                'batch_explore' => [
                    'requested' => 10,
                    'completed' => 10,
                    'stop_reason' => null,
                ],
            ]);
        $this->app->instance(SubAreaExplorationService::class, $subAreaService);

        $response = $this->withoutMiddleware(\App\Http\Middleware\CheckCharacterSelected::class)
            ->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id])
            ->post(route('battle.sub_area.explore', ['discovery' => $discovery->id]), [
                'continue_chain' => 1,
                'batch_count' => 10,
            ]);

        $response->assertRedirect(route('battle.result'));
        $response->assertSessionHas('battleData.selectedExploreCount', 10);
        $response->assertSessionHas('battleData.result.batch_explore.completed', 10);
        $response->assertSessionHas('battleData.result.special_event', 'sub_area_explore');
        $response->assertSessionHas('battleData.result.sub_area_discovery_id', $discovery->id);
        $response->assertSessionHas('exploration_selected_count.' . $character->id, 10);
    }

    public function test_frozen_character_cannot_start_sub_area_exploration(): void
    {
        $character = $this->characterWithStamina(50);
        $character->forceFill(['is_frozen' => true])->save();
        $area = Area::query()->create([
            'name' => '凍結確認用の入口',
            'slug' => 'frozen-sub-area-entry-test',
        ]);
        $subArea = SubArea::query()->create([
            'name' => '凍結確認用の亜域',
            'is_enabled' => true,
        ]);
        $route = SubAreaRoute::query()->create([
            'sub_area_id' => $subArea->id,
            'source_area_id' => $area->id,
            'route_name' => '凍結確認路',
            'is_enabled' => true,
        ]);
        $discovery = CharacterSubAreaRouteDiscovery::query()->create([
            'character_id' => $character->id,
            'sub_area_route_id' => $route->id,
            'discovered_at' => now(),
        ]);

        $subAreaService = Mockery::mock(SubAreaExplorationService::class);
        $subAreaService->shouldReceive('explore')->never();
        $subAreaService->shouldReceive('exploreRepeated')->never();
        $this->app->instance(SubAreaExplorationService::class, $subAreaService);

        $response = $this->withoutMiddleware(\App\Http\Middleware\CheckCharacterSelected::class)
            ->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id])
            ->post(route('battle.sub_area.explore', ['discovery' => $discovery->id]), [
                'batch_count' => 50,
            ]);

        $response->assertRedirect(route('home'));
        $response->assertSessionHas('error', 'このアカウントは凍結されています。お問い合わせください。');
        $response->assertSessionMissing('battleData');
    }

    public function test_retreating_from_a_depth_gate_runs_one_battle_and_keeps_the_selected_count(): void
    {
        $character = $this->characterWithStamina(50);
        $character->forceFill(['current_hp' => 30])->save();
        $area = Area::query()->create([
            'name' => '深度入口の試験場',
            'slug' => 'depth-gate-retreat-test',
        ]);
        $sessionKey = 'exploration_selected_count.' . $character->id;

        $areaService = Mockery::mock(AreaService::class);
        $areaService->shouldReceive('canEnterArea')->twice()->andReturnTrue();
        $this->app->instance(AreaService::class, $areaService);

        $regionDepthDungeonService = Mockery::mock(RegionDepthDungeonService::class);
        $regionDepthDungeonService->shouldReceive('isRegionDepthArea')->andReturnFalse();
        $this->app->instance(RegionDepthDungeonService::class, $regionDepthDungeonService);

        $staminaService = Mockery::mock(ExplorationStaminaService::class);
        $staminaService->shouldReceive('enabled')->never();
        $this->app->instance(ExplorationStaminaService::class, $staminaService);

        $explorationService = Mockery::mock(ExplorationService::class);
        $explorationService->shouldReceive('exploreRepeated')->never();
        $explorationService->shouldReceive('explore')->once()->andReturn([
            'result' => 'victory',
            'enemy' => (object) ['name' => '試験敵'],
            'exploration_progress' => ['depth_transitions' => []],
        ]);
        $this->app->instance(ExplorationService::class, $explorationService);

        $response = $this->withoutMiddleware()
            ->actingAs($character->user)
            ->withSession([
                'current_character_id' => $character->id,
                $sessionKey => 50,
            ])
            ->post(route('battle.depth.retreat', ['area' => $area->id]));

        $response->assertRedirect(route('battle.result'));
        $response->assertSessionHas('status', '危険な入口から引き返し、現在のエリア探索を続けます。');
        $response->assertSessionHas('battleData.selectedExploreCount', 50);
        $response->assertSessionHas($sessionKey, 50);
    }

    private function characterWithStamina(int $stamina): Character
    {
        return Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => '50回探索テスト冒険者',
            'hp_base' => 100,
            'mp_base' => 100,
            'current_hp' => 100,
            'current_mp' => 100,
            'wins' => 0,
            'money' => 0,
            'explore_stamina' => $stamina,
            'explore_stamina_max' => 250,
            'explore_stamina_updated_at' => now(),
        ]);
    }

    private function allowNormalAreaExploration(): void
    {
        $areaService = Mockery::mock(AreaService::class);
        $areaService->shouldReceive('canEnterArea')->andReturnTrue();
        $this->app->instance(AreaService::class, $areaService);

        $regionDepthDungeonService = Mockery::mock(RegionDepthDungeonService::class);
        $regionDepthDungeonService->shouldReceive('isRegionDepthArea')->andReturnFalse();
        $this->app->instance(RegionDepthDungeonService::class, $regionDepthDungeonService);
    }

    private function fakeExplorationService(?int $defeatAt = null, ?int $timeoutAt = null): ExplorationService
    {
        $staminaService = Mockery::mock(ExplorationStaminaService::class);
        $staminaService->shouldReceive('enabled')->andReturnTrue();
        $staminaService->shouldReceive('summary')->andReturnUsing(static fn (Character $character): array => [
            'enabled' => true,
            'current' => (int) $character->explore_stamina,
            'max' => 250,
            'cost' => 1,
            'recovery_seconds' => 60,
            'next_recovery_seconds' => 60,
        ]);
        $this->app->instance(ExplorationStaminaService::class, $staminaService);

        $statusService = Mockery::mock(CharacterStatusService::class);
        $statusService->shouldReceive('getFinalStats')->andReturn(['max_hp' => 100, 'max_mp' => 100]);
        $this->app->instance(CharacterStatusService::class, $statusService);

        return new class(
            app(BattleService::class),
            app(LevelService::class),
            app(AreaService::class),
            app(BattleLogService::class),
            app(DropService::class),
            app(PublicLogService::class),
            app(KisekiDropService::class),
            app(DiscoveryService::class),
            $defeatAt,
            $timeoutAt,
        ) extends ExplorationService
        {
            public int $exploreCalls = 0;

            public function __construct(
                BattleService $battleService,
                LevelService $levelService,
                AreaService $areaService,
                BattleLogService $battleLogService,
                DropService $dropService,
                PublicLogService $publicLogService,
                KisekiDropService $kisekiDropService,
                DiscoveryService $discoveryService,
                private readonly ?int $defeatAt,
                private readonly ?int $timeoutAt,
            ) {
                parent::__construct(
                    $battleService,
                    $levelService,
                    $areaService,
                    $battleLogService,
                    $dropService,
                    $publicLogService,
                    $kisekiDropService,
                    $discoveryService,
                );
            }

            public function explore(
                Character $character,
                int $areaId,
                bool $isBossBattle = false,
                ?string $forcedEvent = null,
                bool $skipBattleCooldown = false,
            ): array {
                $this->exploreCalls++;
                $character->explore_stamina = max(0, (int) $character->explore_stamina - 1);
                $character->save();

                $result = $this->timeoutAt === $this->exploreCalls
                    ? 'timeout'
                    : ($this->defeatAt === $this->exploreCalls ? 'defeat' : 'victory');

                return [
                    'result' => $result,
                    'turn_count' => $result === 'timeout' ? 50 : 1,
                    'enemy' => (object) ['name' => '試験敵'],
                    'log' => '',
                    'logs' => [],
                    'exp_gained' => 1,
                    'gold_gained' => 1,
                    'job_exp_gained' => 1,
                    'level_up_details' => [],
                    'material_drop' => [],
                    'equipment_drops' => [],
                    'new_discoveries' => [],
                    'gold_loss' => $result === 'timeout' ? ['amount' => 100, 'rate_label' => '10%'] : null,
                    'material_penalty' => [],
                    'rescue_support' => null,
                    'valmon_egg_lost' => null,
                ];
            }
        };
    }
}
