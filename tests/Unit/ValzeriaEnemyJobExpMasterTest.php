<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ValzeriaEnemyJobExpMasterTest extends TestCase
{
    public function test_valzeria_enemy_job_exp_master_values_are_at_most_three(): void
    {
        $root = dirname(__DIR__, 2);
        $canonical = $this->readTsv($root . '/docs/monster_master.md', 'enemy_id', 'job_EXP');
        $exported = $this->readTsv($root . '/docs/enemy_master.tsv', 'id', 'job_exp_reward');

        $this->assertCount(424, $canonical);
        $this->assertSame($canonical, $exported);

        foreach ($canonical as $enemyId => $jobExp) {
            $this->assertGreaterThanOrEqual(0, $jobExp, "enemy_id={$enemyId}");
            $this->assertLessThanOrEqual(3, $jobExp, "enemy_id={$enemyId}");
        }
    }

    /** @return array<int, int> */
    private function readTsv(string $path, string $idColumn, string $jobExpColumn): array
    {
        $handle = fopen($path, 'rb');
        $this->assertNotFalse($handle, $path);
        $header = fgetcsv($handle, separator: "\t", escape: '\\');
        $this->assertIsArray($header);
        $columns = array_flip($header);
        $this->assertArrayHasKey($idColumn, $columns);
        $this->assertArrayHasKey($jobExpColumn, $columns);

        $result = [];
        while (($row = fgetcsv($handle, separator: "\t", escape: '\\')) !== false) {
            $enemyId = (int) $row[$columns[$idColumn]];
            $result[$enemyId] = (int) $row[$columns[$jobExpColumn]];
        }
        fclose($handle);
        ksort($result);

        return $result;
    }
}
