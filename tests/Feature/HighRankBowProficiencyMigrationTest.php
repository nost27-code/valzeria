<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class HighRankBowProficiencyMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private const TARGET_JOBS = [
        52 => '蒼天竜騎士',
        62 => '竜冠槍将',
        71 => '黒月の執行者',
        90 => '雷霆武神',
    ];

    public function test_config_adds_bow_only_to_the_four_approved_high_rank_jobs(): void
    {
        $bowJobIds = collect(config('job_equipment_permissions.high_rank'))
            ->filter(fn (array $permission): bool => in_array('bow', $permission['weapons'] ?? [], true))
            ->keys()
            ->map(fn (mixed $jobId): int => (int) $jobId)
            ->sort()
            ->values()
            ->all();

        $this->assertSame([52, 54, 62, 64, 70, 71, 83, 90], $bowJobIds);
    }

    public function test_migration_adds_only_target_bows_is_idempotent_and_rolls_back_cleanly(): void
    {
        $migration = $this->migration();
        $unrelatedBefore = $this->unrelatedPermissionSnapshot();

        $migration->down();

        foreach (array_keys(self::TARGET_JOBS) as $jobId) {
            $this->assertSame(0, $this->bowPermissionCount($jobId));
            $this->assertSame(
                collect(config("job_equipment_permissions.high_rank.{$jobId}.weapons"))
                    ->reject(fn (string $category): bool => $category === 'bow')
                    ->sort()
                    ->values()
                    ->all(),
                $this->weaponPermissions($jobId)
            );
        }

        $migration->up();
        $migration->up();

        foreach (array_keys(self::TARGET_JOBS) as $jobId) {
            $this->assertSame(1, $this->bowPermissionCount($jobId));
            $this->assertSame(
                collect(config("job_equipment_permissions.high_rank.{$jobId}.weapons"))
                    ->sort()
                    ->values()
                    ->all(),
                $this->weaponPermissions($jobId)
            );
        }

        $this->assertSame($unrelatedBefore, $this->unrelatedPermissionSnapshot());
    }

    public function test_migration_rejects_a_job_identity_mismatch_before_writing_any_bow(): void
    {
        $migration = $this->migration();
        $migration->down();
        DB::table('job_classes')->where('id', 90)->update(['name' => '不一致検証職']);

        try {
            $migration->up();
            $this->fail('The migration accepted a mismatched job identity.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('job ID 90', $exception->getMessage());
            $this->assertStringContainsString('雷霆武神', $exception->getMessage());
        }

        foreach (array_keys(self::TARGET_JOBS) as $jobId) {
            $this->assertSame(0, $this->bowPermissionCount($jobId));
        }
    }

    /** @return list<string> */
    private function weaponPermissions(int $jobId): array
    {
        return DB::table('job_weapon_permissions')
            ->where('job_id', $jobId)
            ->orderBy('weapon_category')
            ->pluck('weapon_category')
            ->map(fn (mixed $category): string => (string) $category)
            ->all();
    }

    private function bowPermissionCount(int $jobId): int
    {
        return DB::table('job_weapon_permissions')
            ->where('job_id', $jobId)
            ->where('weapon_category', 'bow')
            ->count();
    }

    /** @return list<string> */
    private function unrelatedPermissionSnapshot(): array
    {
        return DB::table('job_weapon_permissions')
            ->whereNotIn('job_id', array_keys(self::TARGET_JOBS))
            ->orderBy('job_id')
            ->orderBy('weapon_category')
            ->get(['job_id', 'weapon_category'])
            ->map(fn (object $row): string => "{$row->job_id}:{$row->weapon_category}")
            ->all();
    }

    private function migration(): object
    {
        return require base_path('database/migrations/2026_08_24_120000_add_bow_proficiency_to_selected_high_rank_jobs.php');
    }
}
