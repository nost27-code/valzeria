<?php

namespace Tests\Feature;

use Database\Seeders\JobArtSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class FirstJobArtReplacementWaveMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(JobArtSeeder::class);
        $this->assertSame(282, DB::table('skills')->where('skill_type', 'job_art')->count());
    }

    /** @var array<string, string> */
    private const NEW_NAMES = [
        '1:1' => '見切りの呼吸',
        '2:5' => '二段穿ち',
        '5:9' => '大崩拳',
        '9:9' => '蝕みの終端',
        '12:9' => '総力戦',
        '15:1' => '不屈の誓い',
        '29:1' => '静寂の帳',
    ];

    /** @var array<string, string> */
    private const OLD_NAMES = [
        '1:1' => '斬り払い',
        '2:5' => '渾身撃',
        '5:9' => '爆裂闘気',
        '9:9' => '双極断',
        '12:9' => '十面埋伏',
        '15:1' => 'シールドバッシュ',
        '29:1' => '魔力循環',
    ];

    public function test_up_replaces_exactly_seven_natural_keys_preserves_ids_and_is_idempotent(): void
    {
        $migration = $this->migration();
        $newRows = $this->targetRows();
        $ids = collect($newRows)->map(fn (array $row): int => (int) $row['id'])->all();
        $unaffectedBefore = $this->semanticRow(3, 1);

        $migration->down();
        $this->assertSame(self::OLD_NAMES, $this->targetNames());
        $this->assertSame($ids, collect($this->targetRows())->map(fn (array $row): int => (int) $row['id'])->all());

        $migration->up();
        $afterFirst = $this->targetRows();
        $migration->up();

        $this->assertSame(self::NEW_NAMES, $this->targetNames());
        $this->assertSame($afterFirst, $this->targetRows());
        $this->assertSame($ids, collect($afterFirst)->map(fn (array $row): int => (int) $row['id'])->all());
        $this->assertSame($unaffectedBefore, $this->semanticRow(3, 1));
        $this->assertSame(7, count($afterFirst));

        $this->assertSame(['PHYSICAL_DAMAGE', 90, 1, 1], $this->effectTuple(1, 1));
        $this->assertSame(['MULTI_HIT', 145, 2, 1], $this->effectTuple(2, 5));
        $this->assertSame(['PHYSICAL_DAMAGE', 225, 1, 1], $this->effectTuple(5, 9));
        $this->assertSame(['PHYSICAL_DAMAGE', 255, 1, 1], $this->effectTuple(9, 9));
        $this->assertSame(['HYBRID_DAMAGE', 255, 1, 3], $this->effectTuple(12, 9));
        $this->assertSame(['GUARD_BARRIER', 0, 0, 1], $this->effectTuple(15, 1));
        $this->assertSame(['MAGICAL_DAMAGE', 95, 1, 5], $this->effectTuple(29, 1));
        $this->assertSame(40, (int) $this->row(15, 1)->damage_reduction_percent);
        $this->assertSame('NONE', (string) $this->row(15, 1)->limit_group);
        $this->assertSame(0, (int) $this->row(29, 1)->cooldown_turns);
    }

    public function test_up_rejects_duplicate_natural_keys_and_rolls_back_all_prior_updates(): void
    {
        $migration = $this->migration();
        $migration->down();
        $duplicate = (array) $this->row(29, 1);
        unset($duplicate['id']);
        $duplicate['name'] = '静寂の帳・重複検証';
        DB::table('skills')->insert($duplicate);

        try {
            $migration->up();
            $this->fail('The migration did not reject the duplicate natural key.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('expected exactly one row for 29:1, found 2', $exception->getMessage());
        }

        $this->assertSame('斬り払い', (string) $this->row(1, 1)->name, 'Earlier updates must roll back with the duplicate failure.');
    }

    public function test_up_rejects_a_partially_missing_target_set_and_rolls_back_all_prior_updates(): void
    {
        $migration = $this->migration();
        $migration->down();
        DB::table('skills')
            ->where('job_id', 29)
            ->where('learn_rank', 1)
            ->where('skill_type', 'job_art')
            ->delete();

        try {
            $migration->up();
            $this->fail('The migration did not reject the missing natural key.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('expected exactly one row for 29:1, found 0', $exception->getMessage());
        }

        $this->assertSame('斬り払い', (string) $this->row(1, 1)->name, 'Earlier updates must roll back with the missing-row failure.');
    }

    public function test_up_and_down_are_no_ops_before_the_job_art_master_is_seeded(): void
    {
        DB::table('skills')->where('skill_type', 'job_art')->delete();
        $migration = $this->migration();

        $migration->up();
        $migration->down();

        $this->assertSame(0, DB::table('skills')->where('skill_type', 'job_art')->count());
    }

    /** @return array{string, int, int, int} */
    private function effectTuple(int $jobId, int $rank): array
    {
        $row = $this->row($jobId, $rank);

        return [
            (string) $row->effect_template,
            (int) $row->power,
            (int) $row->hit_count,
            (int) $row->duration_turns,
        ];
    }

    /** @return array<string, string> */
    private function targetNames(): array
    {
        return collect(self::NEW_NAMES)->mapWithKeys(function (string $_, string $key): array {
            [$jobId, $rank] = array_map('intval', explode(':', $key));

            return [$key => (string) $this->row($jobId, $rank)->name];
        })->all();
    }

    /** @return array<string, array<string, mixed>> */
    private function targetRows(): array
    {
        return collect(self::NEW_NAMES)->mapWithKeys(function (string $_, string $key): array {
            [$jobId, $rank] = array_map('intval', explode(':', $key));

            return [$key => $this->semanticRow($jobId, $rank)];
        })->all();
    }

    /** @return array<string, mixed> */
    private function semanticRow(int $jobId, int $rank): array
    {
        $values = (array) $this->row($jobId, $rank);
        unset($values['created_at'], $values['updated_at']);

        return $values;
    }

    private function row(int $jobId, int $rank): object
    {
        $row = DB::table('skills')
            ->where('job_id', $jobId)
            ->where('learn_rank', $rank)
            ->where('skill_type', 'job_art')
            ->first();

        $this->assertNotNull($row, "Missing Job Art {$jobId}:{$rank}");

        return $row;
    }

    private function migration(): object
    {
        return require base_path('database/migrations/2026_08_17_150000_replace_first_wave_job_arts.php');
    }
}
