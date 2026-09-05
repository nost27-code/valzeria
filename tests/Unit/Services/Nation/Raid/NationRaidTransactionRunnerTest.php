<?php

namespace Tests\Unit\Services\Nation\Raid;

use App\Services\Nation\Raid\NationRaidTransactionRunner;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class NationRaidTransactionRunnerTest extends TestCase
{
    public function test_only_deadlock_serialization_and_lock_timeout_are_retryable(): void
    {
        $runner = new NationRaidTransactionRunner;
        foreach ([['40001', 1213, true], ['HY000', 1205, true], ['23000', 1062, false], ['42000', 1064, false], ['HY000', 2006, false]] as [$state, $code, $expected]) {
            $error = new \PDOException('injected');
            $error->errorInfo = [$state, $code, 'injected'];
            $this->assertSame($expected, $runner->isRetryable($error));
        }
        $this->assertFalse($runner->isRetryable(new \DomainException('not retryable')));
    }

    public function test_mariadb_session_timeout_is_restored_after_success_and_failure(): void
    {
        $original = DB::getFacadeRoot();
        try {
            foreach ([['mysql', false], ['mysql', true], ['mariadb', false], ['mariadb', true]] as [$driver, $fail]) {
                $connection = Mockery::mock(\Illuminate\Database\Connection::class);
                $connection->shouldReceive('getDriverName')->once()->andReturn($driver);
                $connection->shouldReceive('selectOne')->with('SELECT @@SESSION.innodb_lock_wait_timeout AS value')->once()->andReturn((object) ['value' => 50]);
                $connection->shouldReceive('statement')->with('SET SESSION innodb_lock_wait_timeout = 3')->once()->ordered()->andReturnTrue();
                $connection->shouldReceive('transaction')->with(Mockery::type('callable'), 1)->once()->ordered()
                    ->andReturnUsing(fn ($callback) => $callback());
                $connection->shouldReceive('statement')->with('SET SESSION innodb_lock_wait_timeout = 50')->once()->ordered()->andReturnTrue();
                $manager = Mockery::mock();
                $manager->shouldReceive('connection')->once()->andReturn($connection);
                DB::swap($manager);
                try {
                    $result = (new NationRaidTransactionRunner)->run(function (int $attempt) use ($fail) {
                        $this->assertSame(1, $attempt);
                        if ($fail) {
                            throw new \DomainException('expected');
                        }
                        return 42;
                    });
                    $this->assertSame(42, $result);
                    $this->assertFalse($fail);
                } catch (\DomainException $exception) {
                    $this->assertTrue($fail);
                }
            }
        } finally {
            DB::swap($original);
        }
    }

    public function test_default_retry_budget_has_three_attempts_and_300ms_maximum_total_backoff(): void
    {
        $this->assertSame(3, config('nation_raid.settlement.attempts'));
        $this->assertSame([50, 150], config('nation_raid.settlement.backoff_milliseconds'));
        $this->assertSame(50, config('nation_raid.settlement.jitter_max_milliseconds'));
        $this->assertSame(3, config('nation_raid.settlement.innodb_lock_wait_timeout_seconds'));
        $waitBudget = array_sum(config('nation_raid.settlement.backoff_milliseconds')) + 2 * config('nation_raid.settlement.jitter_max_milliseconds');
        $this->assertSame(300, $waitBudget);
        // これは設定契約だけ。MariaDB実機の複数接続での時間上限証明ではない。
    }
}
