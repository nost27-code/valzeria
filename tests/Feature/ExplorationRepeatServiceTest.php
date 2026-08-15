<?php

namespace Tests\Feature;

use App\Http\Controllers\BattleController;
use App\Models\Character;
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

    private function fakeExplorationService(?int $defeatAt = null): ExplorationService
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

                return [
                    'result' => $this->defeatAt === $this->exploreCalls ? 'defeat' : 'victory',
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
                ];
            }
        };
    }
}
