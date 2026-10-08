<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Character;
use App\Models\User;
use App\Services\ExplorationBatchReadContext;
use App\Services\NormalExplorationBatchService;
use App\Services\RegionDepthDungeonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class NormalExplorationBatchServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_off_runs_the_legacy_callback_without_extra_database_reads(): void
    {
        $this->assertFalse(config('exploration_performance.batch_reads_enabled'));
        $hero = $this->hero();
        DB::enableQueryLog();
        $result = app(NormalExplorationBatchService::class)->run($hero, 999999, function () use ($hero): array {
            $this->assertFalse(app(ExplorationBatchReadContext::class)->activeFor($hero));

            return ['legacy' => true];
        });
        $this->assertSame(['legacy' => true], $result);
        $this->assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_enabled_normal_path_scopes_reads_and_rolls_back_the_callback_on_failure(): void
    {
        config(['exploration_performance.batch_reads_enabled' => true, 'nameless_relics.enabled' => true]);
        $hero = $this->hero();
        $before = $hero->fresh()->money;
        $level = DB::transactionLevel();
        $area = Area::query()->firstOrFail();
        try {
            app(NormalExplorationBatchService::class)->run($hero, (int) $area->id, function () use ($hero): array {
                $this->assertTrue(app(ExplorationBatchReadContext::class)->activeFor($hero));
                $this->assertSame('batch_reads', request()->attributes->get('exploration_processing_mode'));
                $hero->update(['money' => 99]);
                throw new RuntimeException('Synthetic reward failure');
            });
            $this->fail('Expected rollback');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic reward failure', $exception->getMessage());
        }
        $this->assertSame($before, $hero->fresh()->money);
        $this->assertSame($level, DB::transactionLevel());
        $this->assertFalse(app(ExplorationBatchReadContext::class)->activeFor($hero));
    }

    public function test_region_depth_path_keeps_the_existing_callback(): void
    {
        config(['exploration_performance.batch_reads_enabled' => true, 'nameless_relics.enabled' => true]);
        $hero = $this->hero();
        $region = Mockery::mock(RegionDepthDungeonService::class);
        $region->shouldReceive('isRegionDepthArea')->once()->andReturnTrue();
        $this->app->instance(RegionDepthDungeonService::class, $region);
        app(NormalExplorationBatchService::class)->run($hero, (int) Area::query()->firstOrFail()->id, function () use ($hero): array {
            $this->assertFalse(app(ExplorationBatchReadContext::class)->activeFor($hero));

            return [];
        });
    }

    private function hero(): Character
    {
        return Character::create(['user_id' => User::factory()->create()->id, 'name' => 'Batch operation rollback']);
    }

    public function test_nameless_off_keeps_the_legacy_path_even_when_the_rollout_flag_is_enabled(): void
    {
        config(['exploration_performance.batch_reads_enabled' => true, 'nameless_relics.enabled' => false]);
        $hero = $this->hero();
        DB::enableQueryLog();
        app(NormalExplorationBatchService::class)->run($hero, 999999, function () use ($hero): array {
            $this->assertFalse(app(ExplorationBatchReadContext::class)->activeFor($hero));

            return [];
        });
        $this->assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();
    }
}
