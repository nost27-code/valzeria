<?php

namespace Tests\Unit;

use App\Services\ChampBattleTransactionRunner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Processors\MySqlProcessor;
use PHPUnit\Framework\TestCase;

class ChampBattleLockTest extends TestCase
{
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
        }
    }
}
