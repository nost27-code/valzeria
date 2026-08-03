<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ReleaseReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReleaseReadinessServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_a_missing_required_job_exp_rank(): void
    {
        config(['extra_content.contents' => []]);
        DB::table('job_exp_tables')->where('job_level', 5)->delete();

        $issues = app(ReleaseReadinessService::class)->issues();

        $this->assertContains('職業経験値マスタが不足しています（不足ランク: 5）。', $issues);
    }

    public function test_it_reports_inconsistent_job_master_records_without_repairing_them(): void
    {
        config(['extra_content.contents' => []]);
        $user = User::factory()->create();
        $characterId = DB::table('characters')->insertGetId([
            'user_id' => $user->id,
            'name' => 'リリース検証用冒険者',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $jobClass = DB::table('job_classes')->where('max_job_level', '>', 1)->first();
        $this->assertNotNull($jobClass);

        $historyId = DB::table('character_jobs')->insertGetId([
            'character_id' => $characterId,
            'job_class_id' => $jobClass->id,
            'job_level' => 1,
            'job_exp' => 0,
            'is_mastered' => true,
            'mastered_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $issues = app(ReleaseReadinessService::class)->issues();

        $this->assertContains('職業ランク未達のマスター済み履歴が 1 件あります。', $issues);
        $this->assertTrue((bool) DB::table('character_jobs')->where('id', $historyId)->value('is_mastered'));
    }
}
