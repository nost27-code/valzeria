<?php

namespace Tests\Unit;

use App\Services\BattleReadScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BattleReadScopePrelockTest extends TestCase
{
    public function test_pretransaction_reads_never_populate_owned_value_cache(): void
    {
        $original = Schema::getFacadeRoot();
        Schema::swap($original);
        DB::shouldReceive('transactionLevel')->andReturn(0);
        $scope = app(BattleReadScope::class);
        $calls = 0;
        $scope->run(function () use ($scope, &$calls) {
            for ($i = 0; $i < 3; $i++) {
                $this->assertSame($i + 1, $scope->remember('development', 'probe', function () use (&$calls) {
                    return ++$calls;
                }));
            }
        });
        $this->assertSame($original, Schema::getFacadeRoot());
    }
}
