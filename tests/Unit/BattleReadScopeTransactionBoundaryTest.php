<?php

namespace Tests\Unit;

use App\Services\BattleReadScope;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BattleReadScopeTransactionBoundaryTest extends TestCase
{
    public function test_independent_commits_discard_owned_values_but_nested_commits_retain_them(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $scope = app(BattleReadScope::class);
        $reads = 0;
        $read = function () use (&$reads) {
            return ++$reads;
        };
        $scope->run(function () use ($scope, $read) {
            for ($operation = 1; $operation <= 2; $operation++) {
                DB::beginTransaction();
                try {
                    $this->assertSame($operation, $scope->remember('development', 'probe', $read));
                    DB::transaction(fn () => $this->assertSame($operation, $scope->remember('development', 'probe', $read)));
                    $this->assertSame($operation, $scope->remember('development', 'probe', $read));
                    DB::commit();
                } finally {
                    while (DB::transactionLevel() > 0) {
                        DB::rollBack();
                    }
                }
            }
        });
        $this->assertSame(2, $reads);
        $this->assertSame(0, DB::transactionLevel());
    }
}
