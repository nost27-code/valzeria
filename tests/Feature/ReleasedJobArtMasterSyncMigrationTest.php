<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use App\Services\Battle\JobArtHitPower;
use Tests\TestCase;

class ReleasedJobArtMasterSyncMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The later all-job v2 sync migration intentionally seeds jobs 80-94.
        // Remove that later state here because this class characterizes the
        // historical released-only migration in isolation.
        DB::table('skills')
            ->whereBetween('job_id', [80, 94])
            ->where('skill_type', 'job_art')
            ->delete();

        // Exercise the data migration directly so every case starts from its
        // released-master postcondition, independent of the test DB baseline.
        $this->migration()->up();
    }

    public function test_up_syncs_only_released_job_arts_and_preserves_existing_ids(): void
    {
        $this->assertSame(237, $this->releasedArts()->count());

        $existingId = (int) DB::table('skills')
            ->where('job_id', 1)
            ->where('learn_rank', 1)
            ->where('skill_type', 'job_art')
            ->value('id');

        DB::table('skills')->where('id', $existingId)->update([
            'name' => '同期前の古い名称',
            'power' => 1,
            'activation_rate' => 1,
        ]);
        DB::table('skills')
            ->whereBetween('job_id', [44, 69])
            ->where('skill_type', 'job_art')
            ->delete();

        $excludedId = $this->insertExcludedSentinel();
        $excludedBefore = $this->semanticRow($excludedId);

        $this->migration()->up();

        $this->assertSame(237, $this->releasedArts()->count());
        $this->assertSame(78, DB::table('skills')
            ->whereBetween('job_id', [44, 69])
            ->where('skill_type', 'job_art')
            ->count());

        $synced = DB::table('skills')->where('id', $existingId)->first();
        $this->assertNotNull($synced);
        $this->assertSame('斬り払い', $synced->name);
        $this->assertSame(90, (int) $synced->power);
        $this->assertSame(24, (int) $synced->activation_rate);
        $this->assertSame(1, (int) $synced->hit_count);

        $this->assertSame($excludedBefore, $this->semanticRow($excludedId));
        $this->assertSame(1, DB::table('skills')
            ->whereBetween('job_id', [80, 94])
            ->where('skill_type', 'job_art')
            ->count());
    }

    public function test_up_is_semantically_idempotent(): void
    {
        $before = $this->releasedSemanticRows();

        $this->migration()->up();
        $afterFirstRun = $this->releasedSemanticRows();
        $this->migration()->up();
        $afterSecondRun = $this->releasedSemanticRows();

        $this->assertSame($before, $afterFirstRun);
        $this->assertSame($afterFirstRun, $afterSecondRun);
    }

    public function test_up_aborts_before_any_sync_when_a_natural_key_is_duplicated(): void
    {
        $source = (array) DB::table('skills')
            ->where('job_id', 1)
            ->where('learn_rank', 1)
            ->where('skill_type', 'job_art')
            ->first();
        unset($source['id']);
        $source['name'] = '自然キー重複確認用';
        DB::table('skills')->insert($source);

        $unchangedId = (int) DB::table('skills')
            ->where('job_id', 2)
            ->where('learn_rank', 1)
            ->where('skill_type', 'job_art')
            ->value('id');
        DB::table('skills')->where('id', $unchangedId)->update(['power' => 1]);

        try {
            $this->migration()->up();
            $this->fail('The migration did not reject a duplicate natural key.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('duplicate natural key detected', $exception->getMessage());
            $this->assertStringContainsString('job_id=1, learn_rank=1', $exception->getMessage());
        }

        $this->assertSame(1, (int) DB::table('skills')->where('id', $unchangedId)->value('power'));
    }

    public function test_down_is_intentionally_non_destructive(): void
    {
        $before = $this->releasedSemanticRows();

        $this->migration()->down();

        $this->assertSame($before, $this->releasedSemanticRows());
    }

    public function test_every_released_multi_hit_job_art_keeps_its_action_total_power(): void
    {
        $multiHitArts = $this->releasedArts()
            ->filter(static fn (object $row): bool => (int) $row->hit_count > 1)
            ->values();

        $this->assertSame(21, $multiHitArts->count());
        foreach ($multiHitArts as $art) {
            $powers = JobArtHitPower::split((int) $art->power, (int) $art->hit_count);
            $message = sprintf('job_id=%d rank=%d %s', $art->job_id, $art->learn_rank, $art->name);

            $this->assertCount((int) $art->hit_count, $powers, $message);
            $this->assertSame((int) $art->power, array_sum($powers), $message);
        }
    }

    private function insertExcludedSentinel(): int
    {
        $row = (array) DB::table('skills')
            ->where('job_id', 1)
            ->where('learn_rank', 1)
            ->where('skill_type', 'job_art')
            ->first();
        unset($row['id']);
        $row['job_id'] = 80;
        $row['name'] = '未公開職同期対象外確認用';

        return (int) DB::table('skills')->insertGetId($row);
    }

    /** @return array<string, mixed> */
    private function semanticRow(int $id): array
    {
        $row = (array) DB::table('skills')->where('id', $id)->first();
        unset($row['created_at'], $row['updated_at']);

        return $row;
    }

    /** @return array<int, array<string, mixed>> */
    private function releasedSemanticRows(): array
    {
        return $this->releasedArts()
            ->map(function (object $row): array {
                $values = (array) $row;
                unset($values['created_at'], $values['updated_at']);

                return $values;
            })
            ->all();
    }

    private function releasedArts()
    {
        return DB::table('skills')
            ->where('skill_type', 'job_art')
            ->where(function ($query): void {
                $query
                    ->whereBetween('job_id', [1, 38])
                    ->orWhereBetween('job_id', [44, 79])
                    ->orWhereBetween('job_id', [95, 99]);
            })
            ->orderBy('job_id')
            ->orderBy('learn_rank')
            ->get();
    }

    private function migration(): object
    {
        return require dirname(__DIR__, 2).'/database/migrations/2026_08_09_230000_sync_released_job_art_master.php';
    }
}
