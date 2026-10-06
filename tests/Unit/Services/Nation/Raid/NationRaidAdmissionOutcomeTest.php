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
