<?php

namespace Tests\Unit\Services\Nation\Raid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NationRaidAdmissionOutcomeTest extends TestCase
{
    #[DataProvider('invalidOutcomes')]
    public function test_unexpected_worker_results_are_not_accepted_as_race_success(array $rows): void
    {
        require_once dirname(__DIR__, 5).'/scripts/verify/support/NationRaidPhase4MariaDbSafety.php';
        require_once dirname(__DIR__, 5).'/scripts/verify/support/NationRaidPhase4MariaDbHarness.php';
        $method = new \ReflectionMethod(\NationRaidPhase4MariaDbHarness::class, 'admissionRaceOutcomes');
        $this->expectException(\RuntimeException::class);
        $method->invoke(new \NationRaidPhase4MariaDbHarness, $rows);
    }

    public static function invalidOutcomes(): array
    {
        return [
            [[['outcome' => 'created'], ['outcome' => 'created']]],
            [[['outcome' => 'existing'], ['outcome' => 'blocked_pending']]],
            [[['outcome' => 'created'], ['outcome' => 'unexpected_error']]],
            [[['outcome' => 'created'], ['outcome' => 'blocked_owner_busy', 'database_error_code' => 1213]]],
            [[['outcome' => 'created'], ['outcome' => 'blocked_owner_busy']]],
        ];
    }

    public function test_reward_busy_refusal_requires_a_real_1205_cause(): void
    {
        require_once dirname(__DIR__, 5).'/scripts/verify/support/NationRaidPhase4MariaDbSafety.php';
        require_once dirname(__DIR__, 5).'/scripts/verify/support/NationRaidPhase4MariaDbHarness.php';
        $method = new \ReflectionMethod(\NationRaidPhase4MariaDbHarness::class, 'rewardTimeoutCause');
        $harness = new \NationRaidPhase4MariaDbHarness;
        $message = 'ほかの操作を処理中です。完了してから、もう一度報酬を受け取ってください。';
        $cause = static function (int $code): \Illuminate\Database\QueryException {
            $pdo = new \PDOException('isolated lock cause');
            $pdo->errorInfo = ['HY000', $code, 'fixture'];
            return new \Illuminate\Database\QueryException('mysql', 'select * from characters for update nowait', [], $pdo);
        };
        $lock = $cause(1205);
        $this->assertSame($lock, $method->invoke($harness, new \DomainException($message, previous: $lock)));
        $this->assertNull($method->invoke($harness, new \DomainException($message)));
        $this->assertNull($method->invoke($harness, new \DomainException('unexpected', previous: $lock)));
        $this->assertNull($method->invoke($harness, new \DomainException($message, previous: $cause(1213))));
        $this->assertNull($method->invoke($harness, new \DomainException($message, previous: $cause(3572))));
        $this->assertNull($method->invoke($harness, new \DomainException($message, previous: new \RuntimeException('not SQL'))));
    }

    public function test_owner_barrier_requires_an_exclusive_lock_and_accepts_nowait(): void
    {
        require_once dirname(__DIR__, 5).'/scripts/verify/support/NationRaidPhase4MariaDbSafety.php';
        require_once dirname(__DIR__, 5).'/scripts/verify/support/NationRaidPhase4MariaDbHarness.php';
        $method = new \ReflectionMethod(\NationRaidPhase4MariaDbHarness::class, 'hasExclusiveOwnerLock');
        $harness = new \NationRaidPhase4MariaDbHarness;
        $query = 'select * from `characters` where id = ? limit 1';
        $this->assertTrue($method->invoke($harness, $query.' for update'));
        $this->assertTrue($method->invoke($harness, $query.' FOR UPDATE NOWAIT'));
        $this->assertFalse($method->invoke($harness, $query));
        $this->assertFalse($method->invoke($harness, $query.' for share'));
        $this->assertFalse($method->invoke($harness, $query.' lock in share mode'));
        $this->assertFalse($method->invoke($harness, $query.' for update skip locked'));
    }

    public function test_only_a_single_commit_and_recognized_refusal_are_accepted(): void
    {
        require_once dirname(__DIR__, 5).'/scripts/verify/support/NationRaidPhase4MariaDbSafety.php';
        require_once dirname(__DIR__, 5).'/scripts/verify/support/NationRaidPhase4MariaDbHarness.php';
        $method = new \ReflectionMethod(\NationRaidPhase4MariaDbHarness::class, 'admissionRaceOutcomes');
        foreach ([1205, 3572] as $code) {
            $method->invoke(new \NationRaidPhase4MariaDbHarness, [
                ['outcome' => 'created'], ['outcome' => 'blocked_owner_busy', 'database_error_code' => $code],
            ]);
        }
        $method->invoke(new \NationRaidPhase4MariaDbHarness, [['outcome' => 'created'], ['outcome' => 'blocked_pending']]);
        $this->addToAssertionCount(3);
    }
}
