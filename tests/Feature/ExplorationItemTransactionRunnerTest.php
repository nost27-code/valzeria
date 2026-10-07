<?php

namespace Tests\Feature;

use App\Services\ExplorationItemTransactionRunner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExplorationItemTransactionRunnerTest extends TestCase
{
    private function runner(): ExplorationItemTransactionRunner
    {
        return new class extends ExplorationItemTransactionRunner
        {
            protected function waitBeforeRetry(int $attempt): void {}
        };
    }

    private function contention(int $code = 1205): QueryException
    {
        $cause = new \PDOException('fixture');
        $cause->errorInfo = [$code === 1213 ? '40001' : 'HY000', $code, 'fixture'];

        return new QueryException('sqlite', 'fixture', [], $cause);
    }

    public function test_short_character_contention_is_retried_beyond_three_attempts(): void
    {
        $attempts = 0;
        $result = $this->runner()->run(1, function () use (&$attempts) {
            if (++$attempts <= 4) {
                throw $this->contention();
            }

            return ['success' => true];
        });
        $this->assertTrue($result['success']);
        $this->assertSame(5, $attempts);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_partial_consumption_rolls_back_before_retry_and_commits_once(): void
    {
        DB::statement('CREATE TABLE recovery_probe (hp INTEGER, items INTEGER, used INTEGER)');
        DB::table('recovery_probe')->insert(['hp' => 20, 'items' => 2, 'used' => 0]);
        $attempts = 0;
        $result = $this->runner()->run(1, function (string &$phase) use (&$attempts) {
            $phase = 'recovery_save';
            $this->assertSame(0, (int) DB::table('recovery_probe')->value('used'));
            DB::table('recovery_probe')->update(['hp' => 50, 'items' => 1, 'used' => 1]);
            if (++$attempts === 1) {
                throw $this->contention(1213);
            }
            return ['success' => true];
        });
        $this->assertTrue($result['success']);
        $this->assertSame(2, $attempts);
        $row = DB::table('recovery_probe')->first();
        $this->assertSame([50, 1, 1], [(int) $row->hp, (int) $row->items, (int) $row->used]);
    }

    public function test_persistent_contention_has_a_bounded_attempt_count_and_no_writes(): void
    {
        foreach (['character_lock' => 8, 'carry_lock' => 3] as $failurePhase => $limit) {
            $attempts = 0;
            $result = $this->runner()->run(1, function (string &$phase) use (&$attempts, $failurePhase) {
                $attempts++;
                $phase = $failurePhase;
                throw $this->contention();
            });
            $this->assertFalse($result['success']);
            $this->assertSame($limit, $attempts);
            $this->assertSame(0, DB::transactionLevel());
        }
    }

    public function test_outer_transaction_owner_handles_contention_without_an_internal_retry(): void
    {
        DB::beginTransaction();
        $attempts = 0;
        try {
            $this->runner()->run(1, function () use (&$attempts) {
                $attempts++;
                throw $this->contention();
            });
            $this->fail('Outer transaction contention must propagate.');
        } catch (QueryException $exception) {
            $this->assertSame(1205, $exception->errorInfo[1]);
            $this->assertSame(1, $attempts);
            $this->assertSame(1, DB::transactionLevel());
        } finally {
            DB::rollBack();
        }
    }

    public function test_unrelated_database_errors_are_not_hidden_as_contention(): void
    {
        $this->expectException(QueryException::class);
        $this->runner()->run(1, fn () => throw $this->contention(1064));
    }
}
