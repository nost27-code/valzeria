<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AllJobArtMasterSyncMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migration()->up();
    }

    public function test_up_synchronizes_exactly_ninety_four_jobs_and_three_ranks(): void
    {
        $rows = $this->allArts();

        $this->assertCount(282, $rows);
        $this->assertSame($this->allJobs(), $rows->pluck('job_id')->map(fn ($id): int => (int) $id)->unique()->values()->all());

        foreach ($rows->groupBy('job_id') as $jobId => $jobRows) {
            $this->assertSame([1, 5, 9], $jobRows->pluck('learn_rank')->map(fn ($rank): int => (int) $rank)->sort()->values()->all(), (string) $jobId);
        }

        $this->assertSame(45, $rows->whereBetween('job_id', [80, 94])->count());
    }

    public function test_up_restores_missing_high_tier_rows_and_preserves_existing_ids(): void
    {
        $existingId = (int) DB::table('skills')
            ->where('job_id', 70)
            ->where('learn_rank', 1)
            ->where('skill_type', 'job_art')
            ->value('id');
        DB::table('skills')->where('id', $existingId)->update(['name' => '旧名称', 'power' => 1]);
        DB::table('skills')
            ->whereBetween('job_id', [80, 94])
            ->where('skill_type', 'job_art')
            ->delete();

        $this->migration()->up();

        $this->assertSame(282, $this->allArts()->count());
        $this->assertSame($existingId, (int) DB::table('skills')
            ->where('job_id', 70)
            ->where('learn_rank', 1)
            ->where('skill_type', 'job_art')
            ->value('id'));
        $this->assertNotSame('旧名称', (string) DB::table('skills')->where('id', $existingId)->value('name'));
        $this->assertNotSame(1, (int) DB::table('skills')->where('id', $existingId)->value('power'));
    }

    public function test_up_is_semantically_idempotent(): void
    {
        $before = $this->semanticRows();

        $this->migration()->up();
        $afterFirst = $this->semanticRows();
        $this->migration()->up();

        $this->assertSame($before, $afterFirst);
        $this->assertSame($afterFirst, $this->semanticRows());
    }

    public function test_up_aborts_before_sync_when_a_natural_key_is_duplicated(): void
    {
        $source = (array) DB::table('skills')
            ->where('job_id', 90)
            ->where('learn_rank', 1)
            ->where('skill_type', 'job_art')
            ->first();
        unset($source['id']);
        $source['name'] = '重複検証';
        DB::table('skills')->insert($source);

        try {
            $this->migration()->up();
            $this->fail('The migration did not reject a duplicate natural key.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('duplicate natural key detected', $exception->getMessage());
            $this->assertStringContainsString('job_id=90, learn_rank=1', $exception->getMessage());
        }
    }

    public function test_down_never_deletes_player_referenced_job_arts(): void
    {
        $before = $this->semanticRows();

        $this->migration()->down();

        $this->assertSame($before, $this->semanticRows());
    }

    private function allArts()
    {
        return DB::table('skills')
            ->where('skill_type', 'job_art')
            ->whereIn('job_id', $this->allJobs())
            ->orderBy('job_id')
            ->orderBy('learn_rank')
            ->get();
    }

    /** @return array<int, array<string, mixed>> */
    private function semanticRows(): array
    {
        return $this->allArts()->map(function (object $row): array {
            $values = (array) $row;
            unset($values['created_at'], $values['updated_at']);

            return $values;
        })->all();
    }

    /** @return list<int> */
    private function allJobs(): array
    {
        return [...range(1, 38), ...range(44, 99)];
    }

    private function migration(): object
    {
        return require dirname(__DIR__, 2).'/database/migrations/2026_08_14_140000_sync_all_job_art_master_for_v2.php';
    }
}
