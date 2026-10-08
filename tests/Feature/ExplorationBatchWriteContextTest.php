<?php

namespace Tests\Feature;

use App\Http\Middleware\CommitExplorationRequest;
use App\Models\Area;
use App\Models\Character;
use App\Models\CharacterEnemyDiscovery;
use App\Models\Enemy;
use App\Models\User;
use App\Services\BattleLogService;
use App\Services\EnemyBookService;
use App\Services\EnemyDiscoveryService;
use App\Services\ExplorationBatchWriteContext;
use App\Services\NormalExplorationBatchService;
use App\Services\PlayerLifecycleEventService;
use App\Services\GoldService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ExplorationBatchWriteContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['exploration_performance.batch_reads_enabled' => true,
            'exploration_performance.batch_discoveries_enabled' => true, 'nameless_relics.enabled' => true]);
        Carbon::setTestNow('2026-10-08 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_discoveries_preserve_first_and_last_times_and_existing_counts_with_fewer_writes(): void
    {
        [$hero, $enemy] = $this->fixture();
        $service = app(EnemyDiscoveryService::class);
        $service->recordBattle($hero->id, $enemy->id, 'win');
        $perform = function () use ($hero, $enemy, $service): array {
            foreach (['defeat', 'win', 'victory', 'timeout', 'win'] as $i => $result) {
                Carbon::setTestNow('2026-10-08 12:01:0'.$i);
                $service->recordBattle($hero->id, $enemy->id, $result);
            }

            return [];
        };
        DB::beginTransaction();
        DB::enableQueryLog();
        $perform();
        $oldWrites = count(DB::getQueryLog());
        DB::disableQueryLog();
        $old = DB::table(EnemyDiscoveryService::TABLE)->first();
        DB::rollBack();
        DB::enableQueryLog();
        DB::flushQueryLog();
        app(NormalExplorationBatchService::class)->run($hero, $enemy->area_id, $perform);
        $writes = array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], EnemyDiscoveryService::TABLE)
            && preg_match('/^(insert|update)/i', $q['query']));
        DB::disableQueryLog();
        $this->assertEquals($old, DB::table(EnemyDiscoveryService::TABLE)->first());
        $this->assertCount(3, $writes);
        $this->assertLessThan($oldWrites, count($writes));
        $this->assertSame(4, (int) $old->defeat_count);
        $this->assertSame('2026-10-08 12:00:00', $old->first_defeated_at);
        $this->assertSame('2026-10-08 12:01:04', $old->last_defeated_at);
    }

    public function test_new_enemy_loss_then_win_and_foreign_character_are_isolated(): void
    {
        [$hero, $enemy] = $this->fixture();
        $foreign = Character::create(['user_id' => User::factory()->create()->id, 'name' => 'Other owner']);
        app(NormalExplorationBatchService::class)->run($hero, $enemy->area_id, function () use ($hero, $foreign, $enemy): array {
            $service = app(EnemyDiscoveryService::class);
            $service->recordBattle($hero->id, $enemy->id, 'lose');
            Carbon::setTestNow('2026-10-08 12:01:00');
            $service->recordBattle($hero->id, $enemy->id, 'win');
            $service->recordBattle($foreign->id, $enemy->id, 'win');
            $this->assertSame(0, DB::table(EnemyDiscoveryService::TABLE)->where('character_id', $hero->id)->count());
            $this->assertSame(1, DB::table(EnemyDiscoveryService::TABLE)->where('character_id', $foreign->id)->count());

            return [];
        });
        $record = CharacterEnemyDiscovery::where('character_id', $hero->id)->firstOrFail();
        $this->assertSame('2026-10-08 12:00:00', $record->first_encountered_at->toDateTimeString());
        $this->assertSame('2026-10-08 12:01:00', $record->first_defeated_at->toDateTimeString());
        $this->assertSame(1, $record->defeat_count);
        $this->assertFalse(app(ExplorationBatchWriteContext::class)->activeFor($hero->id));
    }

    public function test_nested_rollback_discards_only_rolled_back_battles_and_book_reads_flush(): void
    {
        [$hero, $enemy] = $this->fixture();
        app(NormalExplorationBatchService::class)->run($hero, $enemy->area_id, function () use ($hero, $enemy): array {
            $record = fn () => app(EnemyDiscoveryService::class)->recordBattle($hero->id, $enemy->id, 'win');
            $record();
            DB::beginTransaction();
            $record();
            DB::beginTransaction();
            $record();
            DB::commit();
            DB::rollBack();
            $record();
            $discoveries = new ReflectionMethod(EnemyBookService::class, 'discoveriesFor');
            $this->assertSame(2, $discoveries->invoke(app(EnemyBookService::class), $hero)[$enemy->id]->defeat_count);
            $record();

            return [];
        });
        $this->assertSame(3, CharacterEnemyDiscovery::firstOrFail()->defeat_count);
    }

    public function test_discovery_flush_failure_rolls_back_reward_audit_and_milestones(): void
    {
        [$hero, $enemy] = $this->fixture();
        DB::statement("CREATE TRIGGER synthetic_discovery_failure BEFORE UPDATE ON character_enemy_discoveries BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
        try {
            app(NormalExplorationBatchService::class)->run($hero, $enemy->area_id, function () use ($hero, $enemy): array {
                $this->recordRewardAndAudit($hero, $enemy);

                return [];
            });
            $this->fail('Expected flush failure');
        } catch (QueryException) {
            $this->assertEmpty($this->snapshot($hero)['gold_transactions']);
        }
        $this->assertSame(0, (int) $hero->fresh()->money);
        foreach (['battle_logs', 'player_lifecycle_events', EnemyDiscoveryService::TABLE] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertFalse(app(ExplorationBatchWriteContext::class)->activeFor($hero->id));
        DB::statement('DROP TRIGGER synthetic_discovery_failure');
        app(NormalExplorationBatchService::class)->run($hero->fresh(), $enemy->area_id, function () use ($hero, $enemy): array {
            $this->recordRewardAndAudit($hero->fresh(), $enemy);

            return [];
        });
        $this->assertSame(7, (int) $hero->fresh()->money);
        $this->assertSame(1, CharacterEnemyDiscovery::firstOrFail()->defeat_count);
    }

    public function test_flushed_nested_savepoint_rollback_restores_prior_pending_counts_once(): void
    {
        [$hero, $enemy] = $this->fixture();
        app(NormalExplorationBatchService::class)->run($hero, $enemy->area_id, function () use ($hero, $enemy): array {
            $service = app(EnemyDiscoveryService::class);
            $service->recordBattle($hero->id, $enemy->id, 'win');
            DB::beginTransaction();
            $service->recordBattle($hero->id, $enemy->id, 'win');
            app(ExplorationBatchWriteContext::class)->flush();
            $this->assertSame(2, CharacterEnemyDiscovery::firstOrFail()->defeat_count);
            DB::rollBack();
            $this->assertDatabaseCount(EnemyDiscoveryService::TABLE, 0);

            return [];
        });
        $this->assertSame(1, CharacterEnemyDiscovery::firstOrFail()->defeat_count);
    }

    public function test_gold_ledger_failure_after_balance_change_rolls_back_prior_battles(): void
    {
        [$hero, $enemy] = $this->fixture();
        DB::statement("CREATE TRIGGER synthetic_gold_failure BEFORE INSERT ON gold_transactions WHEN NEW.balance_after = 14 BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
        $beforeLevel = DB::transactionLevel();
        try {
            app(NormalExplorationBatchService::class)->run($hero, $enemy->area_id, function () use ($hero, $enemy): array {
                $this->recordRewardAndAudit($hero, $enemy);
                $this->recordRewardAndAudit($hero, $enemy);

                return [];
            });
            $this->fail('Expected ledger failure');
        } catch (QueryException) {
            $this->assertSame($beforeLevel, DB::transactionLevel());
        }
        $this->assertSame(0, (int) $hero->fresh()->money);
        foreach (['gold_transactions', 'battle_logs', 'player_lifecycle_events', EnemyDiscoveryService::TABLE] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertFalse(app(ExplorationBatchWriteContext::class)->activeFor($hero->id));
    }

    public function test_multiple_enemies_preserve_logical_records_and_existing_discovery_ids(): void
    {
        [$hero, $enemy] = $this->fixture();
        $other = Enemy::create(['area_id' => $enemy->area_id, 'name' => 'Other synthetic enemy', 'is_boss' => false]);
        $service = app(EnemyDiscoveryService::class);
        $service->recordBattle($hero->id, $enemy->id, 'win');
        $existingId = CharacterEnemyDiscovery::firstOrFail()->id;
        $operation = function () use ($hero, $enemy, $other, $service): array {
            foreach ([$enemy, $enemy, $other, $enemy, $other] as $i => $target) {
                Carbon::setTestNow('2026-10-08 12:00:0'.$i);
                $service->recordBattle($hero->id, $target->id, 'win');
            }

            return [];
        };
        DB::beginTransaction();
        $operation();
        $columns = ['character_id', 'enemy_id', 'first_encountered_at', 'first_defeated_at', 'last_defeated_at', 'defeat_count', 'created_at', 'updated_at'];
        $old = DB::table(EnemyDiscoveryService::TABLE)->orderBy('enemy_id')->get($columns)->toArray();
        DB::rollBack();
        app(NormalExplorationBatchService::class)->run($hero, $enemy->area_id, $operation);
        $this->assertEquals($old, DB::table(EnemyDiscoveryService::TABLE)->orderBy('enemy_id')->get($columns)->toArray());
        $this->assertSame($existingId, CharacterEnemyDiscovery::where('enemy_id', $enemy->id)->firstOrFail()->id);
    }

    public function test_unconfirmed_lifecycle_write_is_retried_and_confirmed_record_is_reused(): void
    {
        [$hero, $enemy] = $this->fixture();
        app(NormalExplorationBatchService::class)->run($hero, $enemy->area_id, function () use ($hero): array {
            DB::statement("CREATE TRIGGER synthetic_lifecycle_failure BEFORE INSERT ON player_lifecycle_events BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
            $service = app(PlayerLifecycleEventService::class);
            $service->recordFirstBattle($hero, 'win');
            $this->assertDatabaseCount('player_lifecycle_events', 0);
            DB::statement('DROP TRIGGER synthetic_lifecycle_failure');
            $service->recordFirstBattle($hero, 'win');
            $this->assertDatabaseCount('player_lifecycle_events', 2);
            $service->recordFirstBattle($hero, 'win'); // First successful write changes cache generation.
            DB::enableQueryLog();
            $service->recordFirstBattle($hero, 'victory');
            $this->assertCount(0, DB::getQueryLog());
            DB::disableQueryLog();
            DB::table('player_lifecycle_events')->where('event_key', 'first_victory')->delete();
            $service->recordFirstBattle($hero, 'win');
            $this->assertDatabaseCount('player_lifecycle_events', 2);

            return [];
        });
    }

    public function test_operation_uuid_replay_and_changed_payload_do_not_grant_or_record_twice(): void
    {
        [$hero, $enemy] = $this->fixture();
        $token = (string) Str::uuid();
        $middleware = app(CommitExplorationRequest::class);
        $request = $this->request($hero, $token);
        $middleware->handle($request, function (Request $request) use ($hero, $enemy) {
            $result = app(NormalExplorationBatchService::class)->run($hero, $enemy->area_id, function () use ($hero, $enemy): array {
                $this->recordRewardAndAudit($hero, $enemy);
                $this->recordRewardAndAudit($hero, $enemy);

                return ['result' => 'victory', 'gold_gained' => 14,
                    'batch_explore' => ['requested' => 50, 'completed' => 2, 'stop_reason' => 'hp_pinch']];
            });
            $request->attributes->set('committed_exploration_data', ['result' => $result]);

            return redirect('/synthetic-result');
        });
        $before = $this->snapshot($hero);
        $saved = Crypt::decrypt(DB::table('exploration_requests')->value('battle_data'));
        $this->assertSame(2, $saved['result']['batch_explore']['completed']);
        $this->assertSame('hp_pinch', $saved['result']['batch_explore']['stop_reason']);
        $this->assertSame(config('exploration_performance.batch_state_enabled') ? 'batch_state' : 'batch_discoveries', $request->attributes->get('exploration_processing_mode'));
        $response = $middleware->handle($this->request($hero, $token), fn () => $this->fail('Replay ran exploration'));
        $this->assertSame(url('/synthetic-result'), $response->getTargetUrl());
        $this->assertEquals($before, $this->snapshot($hero));
        try {
            $middleware->handle($this->request($hero, $token, 10), fn () => $this->fail('Changed payload ran exploration'));
            $this->fail('Expected changed payload rejection');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertEquals($before, $this->snapshot($hero));
        $this->assertDatabaseCount('exploration_requests', 1);
    }

    public function test_result_insert_failure_rolls_back_already_flushed_discoveries_and_session_then_allows_same_token(): void
    {
        [$hero, $enemy] = $this->fixture();
        $token = (string) Str::uuid();
        $middleware = app(CommitExplorationRequest::class);
        DB::statement("CREATE TRIGGER synthetic_result_failure BEFORE INSERT ON exploration_requests BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
        $operation = function (Request $request) use ($hero, $enemy) {
            app(NormalExplorationBatchService::class)->run($hero->fresh(), $enemy->area_id, function () use ($hero, $enemy): array {
                $this->recordRewardAndAudit($hero->fresh(), $enemy);

                return [];
            });
            $this->assertSame(1, CharacterEnemyDiscovery::firstOrFail()->defeat_count);
            $request->session()->put('synthetic.changed', true);

            return redirect('/synthetic-result');
        };
        $request = $this->request($hero, $token);
        $sessionBefore = $request->session()->all();
        try {
            $middleware->handle($request, $operation);
            $this->fail('Expected result insert failure');
        } catch (QueryException) {
            $this->assertSame($sessionBefore, $request->session()->all());
        }
        $this->assertSame(0, (int) $hero->fresh()->money);
        foreach (['gold_transactions', 'battle_logs', 'player_lifecycle_events', EnemyDiscoveryService::TABLE, 'exploration_requests'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        DB::statement('DROP TRIGGER synthetic_result_failure');
        $middleware->handle($this->request($hero, $token), $operation);
        $this->assertSame(7, (int) $hero->fresh()->money);
        $this->assertDatabaseCount('exploration_requests', 1);
    }

    public function test_write_flag_alone_does_not_activate_the_new_scope(): void
    {
        [$hero, $enemy] = $this->fixture();
        config(['exploration_performance.batch_reads_enabled' => false]);
        app(NormalExplorationBatchService::class)->run($hero, $enemy->area_id, function () use ($hero, $enemy): array {
            $this->assertFalse(app(ExplorationBatchWriteContext::class)->activeFor($hero->id));
            app(EnemyDiscoveryService::class)->recordBattle($hero->id, $enemy->id, 'win');
            $this->assertSame(1, CharacterEnemyDiscovery::firstOrFail()->defeat_count);

            return [];
        });
    }

    private function fixture(): array
    {
        $hero = Character::create(['user_id' => User::factory()->create(['role' => 'user'])->id,
            'name' => 'Synthetic batch', 'money' => 0]);
        $enemy = Enemy::create(['area_id' => Area::firstOrFail()->id, 'name' => 'Synthetic enemy', 'is_boss' => false]);

        return [$hero, $enemy];
    }

    private function recordRewardAndAudit(Character $hero, Enemy $enemy): void
    {
        app(GoldService::class)->add($hero, 7, 'battle_reward', 'Synthetic reward');
        app(BattleLogService::class)->addLog($hero, $enemy->area_id, $enemy->id, 'normal', 'victory', 1, 7, 1, 0, 'Synthetic battle');
    }

    private function snapshot(Character $hero): array
    {
        $snapshot = ['character' => $hero->fresh()->getAttributes()];
        foreach (['gold_transactions', 'battle_logs', 'player_lifecycle_events', EnemyDiscoveryService::TABLE] as $table) {
            $snapshot[$table] = DB::table($table)->where('character_id', $hero->id)->orderBy('id')->get()->toArray();
        }

        return $snapshot;
    }

    private function request(Character $hero, string $token, int $count = 50): Request
    {
        $request = Request::create('/battle/areas/1/explore', 'POST', ['exploration_request_id' => $token, 'batch_count' => $count]);
        $request->setLaravelSession(app('session')->driver());
        $request->session()->put('current_character_id', $hero->id);
        $this->app->instance('request', $request);
        $request->setUserResolver(fn () => $hero->user);

        return $request;
    }
}
