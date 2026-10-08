<?php

namespace Tests\Feature;

use App\Services\ChampBattleTransactionRunner;
use App\Services\RequestPerformanceCollector;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\TestCase;

class RequestPerformanceRunnerTest extends TestCase
{
    public function test_observation_preserves_rollback_and_retry_outside_an_outer_transaction(): void
    {
        config(['request_performance.enabled' => true]);
        $collector = app(RequestPerformanceCollector::class);
        $collector->begin(true);
        DB::statement('CREATE TABLE performance_probe (value INTEGER)');
        $attempts = 0;
        $result = $this->runner()->run(0, function (string &$phase) use (&$attempts) {
            $phase = 'battle_log_save';
            DB::insert('INSERT INTO performance_probe (value) VALUES (1)');
            if (++$attempts === 1) {
                throw $this->lockError();
            }

            return ['ok' => true];
        });
        $this->assertTrue($result['ok']);
        $this->assertSame(2, $attempts);
        $this->assertSame(1, DB::table('performance_probe')->count());
        $profile = $collector->finish(Request::create('/runner-probe'), 200);
        $this->assertSame(1, $profile['errors']['database_lock']);
        $this->assertSame(1, $profile['phases']['battle_log_save']);
    }

    public function test_observation_preserves_the_latest_eight_attempt_entry_budget(): void
    {
        config(['request_performance.enabled' => true]);
        $collector = app(RequestPerformanceCollector::class);
        $collector->begin(true);
        $attempts = 0;
        $result = $this->runner()->run(0, function (string &$phase) use (&$attempts) {
            $phase = 'champ_character_lock';
            $attempts++;
            throw $this->lockError();
        });
        $this->assertFalse($result['ok']);
        $this->assertSame(8, $attempts);
        $this->assertSame(0, DB::transactionLevel());
        $profile = $collector->finish(Request::create('/runner-probe'), 200);
        $this->assertSame(8, $profile['phases']['champ_character_lock']);
    }

    public function test_observation_does_not_swallow_an_outer_transaction_failure(): void
    {
        config(['request_performance.enabled' => true]);
        app(RequestPerformanceCollector::class)->begin(true);
        $expected = $this->lockError();
        DB::beginTransaction();
        try {
            $this->runner()->run(0, function () use ($expected) {
                throw $expected;
            });
            $this->fail('The original exception must reach the outer transaction owner.');
        } catch (QueryException $actual) {
            $this->assertSame($expected, $actual);
            $this->assertSame(1, DB::transactionLevel());
        } finally {
            DB::rollBack();
        }
    }

    private function runner(): ChampBattleTransactionRunner
    {
        return new class extends ChampBattleTransactionRunner
        {
            protected function waitBeforeRetry(int $attempt): void {}
        };
    }

    private function lockError(): QueryException
    {
        $previous = new PDOException('Synthetic lock timeout');
        $previous->errorInfo = ['HY000', 1205, 'Synthetic lock timeout'];

        return new QueryException('mysql', 'select 1', [], $previous);
    }
}
