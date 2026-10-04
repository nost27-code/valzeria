<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\GameplayMetric;
use App\Models\User;
use App\Services\Battle\BattleResult;
use App\Services\GameplayMetricService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDOException;
use Tests\TestCase;

class GameplayMetricPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = ['gameplay_metrics', 'gameplay_job_art_rollups', 'gameplay_job_art_skill_rollups', 'gameplay_job_art_activation_rollups'];

    public function test_waits_for_outer_commit_and_snapshots_each_battle_and_original_hour(): void
    {
        $character = $this->actor();
        $battle = new BattleResult;
        $battle->result = 'victory';
        $battle->turnCount = 2;
        $battle->playerLevelAtStart = 48;
        $this->travelTo(now()->startOfDay()->setTime(12, 59, 59));
        $service = app(GameplayMetricService::class);

        DB::transaction(function () use ($character, $battle, $service): void {
            DB::transaction(function () use ($character, $battle, $service): void {
                $service->recordJobArtBattle($character, 'normal', $battle);
                $battle->turnCount = 7;
                $battle->playerLevelAtStart = 50;
                $service->recordJobArtBattle($character, 'normal', $battle);
                $battle->turnCount = 99;
            });
            $service->recordExplorationRequest($character, 'normal', 1, ['result' => 'victory'], ['danger_rate' => null, 'stamina' => null]);
            $this->assertDatabaseCount('gameplay_metrics', 0);
            $this->travel(2)->seconds();
        });

        $rows = GameplayMetric::where('metric_type', GameplayMetric::TYPE_JOB_ART_BATTLE)->orderBy('id')->get();
        $this->assertSame([2, 7], $rows->map(fn ($row) => $row->payload['turn_count'])->all());
        $this->assertSame([48, 50], $rows->map(fn ($row) => $row->payload['character_level_at_start'])->all());
        $this->assertSame(['12:59:59', '12:59:59'], $rows->map(fn ($row) => $row->created_at->format('H:i:s'))->all());
        $this->assertSame(2, DB::table('gameplay_job_art_rollups')->whereTime('bucket_started_at', '12:00:00')->count());
        $this->assertDatabaseCount('gameplay_metrics', 3);
    }

    public function test_rolled_back_battles_never_leave_raw_or_aggregate_metrics(): void
    {
        $character = $this->actor();
        DB::beginTransaction();
        DB::transaction(fn () => app(GameplayMetricService::class)->recordJobArtBattle($character, 'normal', $this->battle()));
        DB::rollBack();
        $this->assertNoMetrics();

        DB::transaction(function () use ($character): void {
            DB::beginTransaction();
            app(GameplayMetricService::class)->recordJobArtBattle($character, 'normal', $this->battle());
            DB::rollBack();
        });
        $this->assertNoMetrics();
    }

    public function test_transient_rollup_failure_retries_all_metrics_once_without_replaying_rewards(): void
    {
        Log::spy();
        $character = $this->actor();
        $attempts = 0;
        DB::connection()->beforeExecuting(function ($sql) use (&$attempts): void {
            if (str_starts_with($sql, 'insert into') && str_contains($sql, 'gameplay_job_art_activation_rollups') && ++$attempts < 3) {
                throw $this->queryError(1205);
            }
        });

        $gameplayExecutions = 0;
        DB::transaction(function () use ($character, &$gameplayExecutions): void {
            $gameplayExecutions++;
            $character->increment('exp', 17);
            app(GameplayMetricService::class)->recordJobArtBattle($character, 'normal', $this->battle());
        });

        $this->assertSame(1, $gameplayExecutions);
        $this->assertSame(17, (int) $character->fresh()->exp);
        $this->assertSame(3, $attempts);
        $this->assertDatabaseCount('gameplay_metrics', 1);
        $this->assertSame(1, (int) DB::table('gameplay_job_art_rollups')->sum('battles'));
        $this->assertSame(2, (int) DB::table('gameplay_job_art_skill_rollups')->sum('activations'));
        $this->assertSame(2, (int) DB::table('gameplay_job_art_activation_rollups')->sum('attempts'));
        Log::shouldNotHaveReceived('warning');
    }

    public function test_permanent_failure_rolls_back_every_metric_but_keeps_gameplay_and_logs_no_private_data(): void
    {
        Log::spy();
        $character = $this->actor();
        $attempts = 0;
        DB::connection()->beforeExecuting(function ($sql) use (&$attempts): void {
            if (str_starts_with($sql, 'insert into') && str_contains($sql, 'gameplay_job_art_activation_rollups')) {
                $attempts++;
                throw $this->queryError(1406);
            }
        });
        DB::transaction(function () use ($character): void {
            $character->increment('exp', 17);
            app(GameplayMetricService::class)->recordJobArtBattle($character, 'normal', $this->battle());
        });

        $this->assertNoMetrics();
        $this->assertSame(17, (int) $character->fresh()->exp);
        $this->assertSame(1, $attempts);
        Log::shouldHaveReceived('warning')->once()->withArgs(function ($message, $context): bool {
            $this->assertSame('gameplay_job_art_activation_rollups', $context['phase']);
            $this->assertSame(1406, $context['database_error_code']);
            $this->assertSame('HY000', $context['sql_state']);
            $this->assertSame(1, $context['attempts']);
            $this->assertStringNotContainsString('PRIVATE', json_encode([$message, $context]));
            $this->assertArrayNotHasKey('character_id', $context);

            return true;
        });
    }

    public function test_exhausted_lock_retries_do_not_leave_partial_or_duplicate_counts(): void
    {
        Log::spy();
        $character = $this->actor();
        $attempts = 0;
        DB::connection()->beforeExecuting(function ($sql) use (&$attempts): void {
            if (str_starts_with($sql, 'insert into') && str_contains($sql, 'gameplay_job_art_skill_rollups')) {
                $attempts++;
                throw $this->queryError(1205);
            }
        });
        app(GameplayMetricService::class)->recordJobArtBattle($character, 'normal', $this->battle());
        $this->assertSame(3, $attempts);
        $this->assertNoMetrics();
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context) => $context['attempts'] === 3
            && $context['database_error_code'] === 1205 && $context['phase'] === 'gameplay_job_art_skill_rollups');
    }

    public function test_raw_insert_failure_never_updates_rollups(): void
    {
        $character = $this->actor();
        DB::connection()->beforeExecuting(function ($sql): void {
            if (str_starts_with($sql, 'insert into') && preg_match('/["`]gameplay_metrics["`]/', $sql)) {
                throw $this->queryError(1406);
            }
        });
        app(GameplayMetricService::class)->recordJobArtBattle($character, 'normal', $this->battle());
        $this->assertNoMetrics();
    }

    public function test_writes_rollups_in_key_order_without_changing_battle_or_loadout_order(): void
    {
        $battle = $this->battle();
        app(GameplayMetricService::class)->recordJobArtBattle($this->actor(), 'normal', $battle);
        foreach (['gameplay_job_art_skill_rollups', 'gameplay_job_art_activation_rollups'] as $table) {
            $this->assertSame([1, 2], DB::table($table)->orderBy('id')->pluck('skill_id')->map(fn ($id) => (int) $id)->all());
        }
        $payload = GameplayMetric::sole()->payload;
        $this->assertSame([2, 1], array_column($payload['skills'], 'skill_id'));
        $this->assertSame([2, 1], array_column($payload['loadout'], 'skill_id'));
    }

    private function actor(): Character
    {
        return Character::create(['user_id' => User::factory()->create(['role' => 'user'])->id,
            'name' => 'metric-'.str()->random(8), 'current_hp' => 100, 'current_mp' => 10, 'exp' => 0]);
    }

    private function battle(): array
    {
        return ['result' => 'victory', 'turn_count' => 2, 'character_level_at_start' => 48,
            'job_art_loadout' => [['skill_id' => 2], ['skill_id' => 1]],
            'job_art_usage' => [['skill_id' => 2, 'activation_count' => 1], ['skill_id' => 1, 'activation_count' => 1]],
            'job_art_activation_attempts' => [
                ['skill_id' => 2, 'effective_rate' => 50, 'activation_roll' => 25],
                ['skill_id' => 1, 'effective_rate' => 50, 'activation_roll' => 25],
            ]];
    }

    private function queryError(int $code): QueryException
    {
        $previous = new PDOException('PRIVATE exception details');
        $previous->errorInfo = ['HY000', $code, 'PRIVATE driver message'];

        return new QueryException(DB::connection()->getName(), 'PRIVATE SQL', ['PRIVATE binding'], $previous);
    }

    private function assertNoMetrics(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }
}
