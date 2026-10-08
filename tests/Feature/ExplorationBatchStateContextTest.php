<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Character;
use App\Models\CharacterJob;
use App\Models\CharacterExplorationState;
use App\Models\JobClass;
use App\Models\User;
use App\Services\ExplorationBatchStateContext;
use App\Services\NormalExplorationBatchService;
use App\Services\CharacterStatusService;
use App\Services\GoldService;
use App\Services\BattleLogService;
use App\Models\Enemy;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ExplorationBatchStateContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['exploration_performance.batch_reads_enabled' => true,
            'exploration_performance.batch_discoveries_enabled' => true,
            'exploration_performance.batch_state_enabled' => true, 'nameless_relics.enabled' => true]);
        Carbon::setTestNow('2026-10-08 12:00:00');
        request()->setMethod('POST');
        request()->attributes->set('committed_exploration_token', '00000000-0000-4000-8000-000000000003');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_character_job_and_progress_updates_are_coalesced_and_models_are_isolated(): void
    {
        [$hero, $area] = $this->fixture();
        DB::enableQueryLog();
        DB::flushQueryLog();
        app(NormalExplorationBatchService::class)->run($hero, $area->id, function () use ($hero): array {
            $context = app(ExplorationBatchStateContext::class);
            for ($i = 0; $i < 10; $i++) {
                Carbon::setTestNow('2026-10-08 12:00:0'.$i);
                $hero->money++;
                $context->save($hero);
                $copy = $context->lockedCharacter($hero);
                $this->assertSame($i + 1, (int) $copy->money);
                $copy->money = 999;
                $context->refreshCharacter($hero);
                $this->assertSame($i + 1, (int) $hero->money);
                $job = $context->currentJobFor($hero);
                $job->job_exp++;
                $context->save($job);
                $state = $context->stateFor($hero);
                $state->chain_count++;
                $context->save($state);
                $this->assertSame($i + 1, (int) $context->currentJobFor($hero)->job_exp);
                $this->assertSame($i + 1, (int) $context->stateFor($hero)->chain_count);
            }
            Carbon::setTestNow('2026-10-08 12:01:00');

            return [];
        });
        $updates = array_filter(DB::getQueryLog(), fn ($q) => str_starts_with($q['query'], 'update'));
        DB::disableQueryLog();
        $this->assertCount(3, $updates);
        $this->assertSame(10, (int) $hero->fresh()->money);
        $this->assertSame(10, (int) CharacterJob::firstOrFail()->job_exp);
        $this->assertSame(10, (int) CharacterExplorationState::firstOrFail()->chain_count);
        $this->assertSame('2026-10-08 12:00:09', $hero->fresh()->updated_at->toDateTimeString());
        $this->assertFalse(app(ExplorationBatchStateContext::class)->activeFor($hero));
    }

    public function test_raw_select_and_update_see_pending_values_and_refresh_never_uses_old_rows(): void
    {
        [$hero, $area] = $this->fixture();
        app(NormalExplorationBatchService::class)->run($hero, $area->id, function () use ($hero): array {
            $context = app(ExplorationBatchStateContext::class);
            $hero->money = 10;
            $context->save($hero);
            $this->assertSame(10, (int) DB::table('characters')->where('id', $hero->id)->value('money'));
            $hero->money = 20;
            $context->save($hero);
            DB::table('characters')->where('id', $hero->id)->increment('money', 3);
            $context->refreshCharacter($hero);
            $this->assertSame(23, (int) $hero->money);
            $job = $context->currentJobFor($hero);
            $job->job_exp = 15;
            $context->save($job);
            DB::table('character_jobs')->where('character_id', $hero->id)->increment('job_exp', 2);
            $this->assertSame(17, (int) $context->currentJobFor($hero)->job_exp);

            return [];
        });
    }

    public function test_nested_rollback_restores_flushed_and_unflushed_prior_state_and_fallback_mode(): void
    {
        [$hero, $area] = $this->fixture();
        app(NormalExplorationBatchService::class)->run($hero, $area->id, function () use ($hero): array {
            $context = app(ExplorationBatchStateContext::class);
            $hero->money = 10;
            $context->save($hero);
            DB::beginTransaction();
            $hero->money = 20;
            $context->save($hero);
            DB::beginTransaction();
            $hero->money = 30;
            $context->save($hero);
            $context->fallback();
            $this->assertFalse($context->activeFor($hero));
            DB::commit();
            DB::rollBack();
            $this->assertTrue($context->activeFor($hero));
            $context->refreshCharacter($hero);
            $this->assertSame(10, (int) $hero->money);
            $hero->money++;
            $context->save($hero);

            return [];
        });
        $this->assertSame(11, (int) $hero->fresh()->money);
    }

    public function test_unlisted_fields_and_other_owners_use_immediate_writes(): void
    {
        [$hero, $area] = $this->fixture();
        $other = Character::create(['user_id' => User::factory()->create()->id, 'name' => 'Other synthetic owner']);
        app(NormalExplorationBatchService::class)->run($hero, $area->id, function () use ($hero, $other): array {
            $context = app(ExplorationBatchStateContext::class);
            $hero->money = 10;
            $context->save($hero);
            $hero->name = 'Updated synthetic name';
            $context->save($hero);
            $context->refreshCharacter($hero);
            $this->assertSame('Updated synthetic name', $hero->name);
            $this->assertSame(10, (int) $hero->money);
            $other->money = 5;
            $context->save($other);
            $this->assertFalse($context->activeFor($other));
            $this->assertSame(5, (int) $other->fresh()->money);

            return [];
        });
    }

    public function test_exception_cleans_context_and_rolls_back_both_pending_and_flushed_state(): void
    {
        [$hero, $area] = $this->fixture();
        try {
            app(NormalExplorationBatchService::class)->run($hero, $area->id, function () use ($hero): array {
                $hero->money = 10;
                app(ExplorationBatchStateContext::class)->save($hero);
                app(ExplorationBatchStateContext::class)->flush();
                $hero->money = 20;
                app(ExplorationBatchStateContext::class)->save($hero);
                throw new RuntimeException('Synthetic state failure');
            });
            $this->fail('Expected rollback');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic state failure', $exception->getMessage());
        }
        $this->assertFalse(app(ExplorationBatchStateContext::class)->activeFor($hero));
        $this->assertSame(0, (int) $hero->fresh()->money);
    }

    public function test_state_flag_requires_the_previous_stages(): void
    {
        [$hero, $area] = $this->fixture();
        config(['exploration_performance.batch_discoveries_enabled' => false]);
        app(NormalExplorationBatchService::class)->run($hero, $area->id, function () use ($hero): array {
            $this->assertFalse(app(ExplorationBatchStateContext::class)->activeFor($hero));

            return [];
        });
    }

    public function test_direct_callers_and_client_supplied_tokens_do_not_enable_state_batching(): void
    {
        [$hero, $area] = $this->fixture();
        request()->attributes->remove('committed_exploration_token');
        request()->merge(['exploration_request_id' => '00000000-0000-4000-8000-000000000003']);
        app(NormalExplorationBatchService::class)->run($hero, $area->id, function () use ($hero): array {
            $this->assertFalse(app(ExplorationBatchStateContext::class)->activeFor($hero));
            $this->assertSame('batch_discoveries', request()->attributes->get('exploration_processing_mode'));

            return [];
        });
        request()->attributes->set('committed_exploration_token', '00000000-0000-4000-8000-000000000003');
        request()->setMethod('GET');
        app(NormalExplorationBatchService::class)->run($hero, $area->id, function () use ($hero): array {
            $this->assertFalse(app(ExplorationBatchStateContext::class)->activeFor($hero));

            return [];
        });
    }

    public function test_existing_model_observers_keep_the_immediate_save_path(): void
    {
        [$hero, $area] = $this->fixture();
        $count = 0;
        $dispatcher = Character::getEventDispatcher();
        $event = 'eloquent.updated: '.Character::class;
        $dispatcher->listen($event, function () use (&$count) { $count++; });
        try {
            app(NormalExplorationBatchService::class)->run($hero, $area->id, function () use ($hero, &$count): array {
                $hero->money = 7;
                app(ExplorationBatchStateContext::class)->save($hero);
                $this->assertSame(1, $count);
                $this->assertSame(7, (int) $hero->fresh()->money);

                return [];
            });
            $this->assertSame(1, $count);
        } finally {
            $dispatcher->forget($event);
        }
    }

    public function test_missing_job_and_progress_rows_keep_real_ids_and_latest_values(): void
    {
        [$hero, $area] = $this->fixture();
        $hero->jobHistories()->delete();
        CharacterExplorationState::where('character_id', $hero->id)->delete();
        app(NormalExplorationBatchService::class)->run($hero, $area->id, function () use ($hero, $area): array {
            $context = app(ExplorationBatchStateContext::class);
            $this->assertNull($context->currentJobFor($hero));
            $this->assertNull($context->stateFor($hero));
            app(\App\Services\JobService::class)->addJobExp($hero, 1);
            app(\App\Services\JobService::class)->addJobExp($hero, 1);
            $job = $context->currentJobFor($hero);
            $this->assertTrue($job->exists);
            $this->assertGreaterThan(0, (int) $job->id);
            $this->assertSame(2, (int) $job->job_exp);
            $state = app(\App\Services\ExplorationStateService::class)->getOrStart($hero, $area->id);
            $this->assertTrue($state->exists);
            $this->assertGreaterThan(0, (int) $state->id);
            $state->chain_count = 1;
            $context->save($state);
            $this->assertSame(1, (int) $context->stateFor($hero)->chain_count);

            return [];
        });
        $this->assertSame(1, $hero->jobHistories()->count());
        $this->assertSame(2, (int) $hero->jobHistories()->firstOrFail()->job_exp);
        $this->assertSame(1, CharacterExplorationState::where('character_id', $hero->id)->count());
        $this->assertSame(1, (int) CharacterExplorationState::where('character_id', $hero->id)->value('chain_count'));
    }

    public function test_exp_only_reuses_abilities_but_rank_changes_recalculate_latest_bonuses(): void
    {
        [$hero, $area] = $this->fixture();
        JobClass::whereKey($hero->current_job_id)->update(['bonus_hp' => 4]);
        app(NormalExplorationBatchService::class)->run($hero, $area->id, function () use ($hero): array {
            $context = app(ExplorationBatchStateContext::class);
            $status = app(CharacterStatusService::class);
            $before = $status->getFinalStats($hero);
            $job = $context->currentJobFor($hero);
            $job->job_exp++;
            $context->save($job);
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->assertSame($before, $status->getFinalStats($hero));
            $this->assertCount(0, DB::getQueryLog());
            DB::disableQueryLog();
            $job->job_level++;
            $context->save($job);
            $this->assertGreaterThan($before['max_hp'], $status->getFinalStats($hero)['max_hp']);

            return [];
        });
    }

    public function test_final_state_flush_failure_rolls_back_logs_ledgers_and_already_flushed_discoveries(): void
    {
        [$hero, $area] = $this->fixture();
        $enemy = Enemy::create(['area_id' => $area->id, 'name' => 'Synthetic flush enemy', 'is_boss' => false]);
        DB::statement("CREATE TRIGGER synthetic_final_state_failure BEFORE UPDATE ON characters BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
        try {
            app(NormalExplorationBatchService::class)->run($hero, $area->id, function () use ($hero, $area, $enemy): array {
                app(GoldService::class)->add($hero, 7, 'battle_reward');
                app(BattleLogService::class)->addLog($hero, $area->id, $enemy->id, 'normal', 'victory', 1, 7, 1, 0, 'Synthetic flush failure');

                return [];
            });
            $this->fail('Expected final state flush failure');
        } catch (QueryException) {
            $this->assertSame(0, (int) $hero->fresh()->money);
            foreach (['battle_logs', 'gold_transactions', 'character_enemy_discoveries', 'player_lifecycle_events'] as $table) {
                $this->assertDatabaseCount($table, 0);
            }
        }
        $this->assertFalse(app(ExplorationBatchStateContext::class)->activeFor($hero));
    }

    private function fixture(): array
    {
        $area = Area::firstOrFail();
        $job = JobClass::firstOrFail();
        $hero = Character::create(['user_id' => User::factory()->create()->id, 'name' => 'Synthetic state explorer',
            'money' => 0, 'current_job_id' => $job->id]);
        CharacterJob::create(['character_id' => $hero->id, 'job_class_id' => $job->id, 'job_level' => 1, 'job_exp' => 0]);
        CharacterExplorationState::create(['character_id' => $hero->id, 'area_id' => $area->id, 'chain_count' => 0, 'started_at' => now()]);

        return [$hero, $area];
    }
}
