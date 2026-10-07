<?php

namespace Tests\Unit;

use App\Services\ChampBattleTransactionRunner;
use App\Services\ExplorationItemTransactionRunner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Processors\MySqlProcessor;
use PHPUnit\Framework\TestCase;

class ChampBattleLockTest extends TestCase
{
    public function test_only_incumbent_reference_contention_gets_the_longer_retry_window(): void
    {
        $runner = new class extends ChampBattleTransactionRunner
        {
            public function stops(int $attempt, int $elapsedMs, string $phase, bool $changed = false): bool
            {
                return $this->shouldStopRetrying($attempt, $elapsedMs, $phase, $changed);
            }
        };
        $this->assertFalse($runner->stops(3, 850, 'champ_character_lock'));
        $this->assertFalse($runner->stops(7, 2900, 'champ_character_lock'));
        $this->assertTrue($runner->stops(8, 2900, 'champ_character_lock'));
        $this->assertTrue($runner->stops(4, 3000, 'champ_character_lock'));
        $this->assertTrue($runner->stops(3, 850, 'reward_save'));
        $this->assertTrue($runner->stops(2, 2000, 'champ_state_lock'));
        $this->assertTrue($runner->stops(3, 850, 'champ_character_lock', true));
    }

    public function test_mysql_label_uses_mariadb_nowait_only_for_an_actual_mariadb_server(): void
    {
        foreach ([true, false] as $isMaria) {
            $connection = $this->createMock(MySqlConnection::class);
            $connection->method('isMaria')->willReturn($isMaria);
            foreach ([true, false] as $shared) {
                $query = new Builder(new QueryBuilder($connection, new MySqlGrammar($connection), new MySqlProcessor));
                $query->from('characters')->where('id', 7);
                (new ChampBattleTransactionRunner)->lock($query, $shared);

                $expected = $shared ? 'lock in share mode' : 'for update';
                $this->assertStringEndsWith($expected.($isMaria ? ' nowait' : ''), $query->toSql());
            }

            $query = new Builder(new QueryBuilder($connection, new MySqlGrammar($connection), new MySqlProcessor));
            $query->from('characters')->where('id', 7);
            (new ExplorationItemTransactionRunner)->lock($query);
            $this->assertStringEndsWith('for update'.($isMaria ? ' nowait' : ''), $query->toSql());
        }
    }
}
