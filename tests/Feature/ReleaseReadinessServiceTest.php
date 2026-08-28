<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ReleaseReadinessService;
use Database\Seeders\JobArtSeeder;
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

    public function test_hero_trial_release_readiness_passes_with_released_trial_masters(): void
    {
        $this->assertSame([], app(ReleaseReadinessService::class)->contentIssues('hero_trials'));
    }

    public function test_equipment_book_release_readiness_passes_with_required_tables(): void
    {
        $this->assertSame([], app(ReleaseReadinessService::class)->contentIssues('equipment_book'));
    }

    public function test_hero_trial_release_readiness_reports_a_missing_trial_area(): void
    {
        DB::table('areas')->where('id', 84)->delete();

        $issues = app(ReleaseReadinessService::class)->contentIssues('hero_trials');

        $this->assertContains('英雄試練 dawn_hero の試練場マスタがありません。', $issues);
    }

    public function test_rank5_v6_release_readiness_requires_all_runtime_dependencies(): void
    {
        config([
            'extra_content.contents' => [],
            'battle.job_art_v2.rank5_v6' => true,
            'battle.job_art_v2.dynamic_single' => false,
            'battle.job_art_v2.hit_resolution' => true,
            'battle.job_art_v2.damage_application' => true,
            'battle.job_art_v2.resources' => true,
        ]);

        $issues = app(ReleaseReadinessService::class)->issues();

        $this->assertContains(
            'Rank5 v6.1 flagがONですが、依存flagがOFFです（BATTLE_JOB_ART_DYNAMIC_SINGLE）。',
            $issues,
        );
    }

    public function test_rank5_v6_release_readiness_requires_the_new_master_when_enabled(): void
    {
        config([
            'extra_content.contents' => [],
            'battle.job_art_v2.rank5_v6' => true,
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.hit_resolution' => true,
            'battle.job_art_v2.damage_application' => true,
            'battle.job_art_v2.resources' => true,
        ]);
        $this->seed(JobArtSeeder::class);

        $ready = app(ReleaseReadinessService::class)->issues();
        $this->assertFalse(
            collect($ready)->contains(static fn (string $issue): bool => str_contains($issue, 'Rank5 v6.1')),
            implode(PHP_EOL, $ready),
        );

        DB::table('skills')
            ->where('job_id', 47)
            ->where('learn_rank', 5)
            ->where('skill_type', 'job_art')
            ->update(['power' => 220]);

        $mismatched = app(ReleaseReadinessService::class)->issues();
        $this->assertContains(
            'Rank5 v6.1 flagがONですが、skillsのRank5 masterが新仕様と一致しません（不一致1件）。',
            $mismatched,
        );

        config(['battle.job_art_v2.rank5_v6' => false]);
        $disabled = app(ReleaseReadinessService::class)->issues();
        $this->assertFalse(
            collect($disabled)->contains(static fn (string $issue): bool => str_contains($issue, 'Rank5 v6.1')),
            implode(PHP_EOL, $disabled),
        );
    }

    public function test_rank5_v6_master_comparison_accepts_mariadb_decimal_strings(): void
    {
        $service = app(ReleaseReadinessService::class);
        $matches = \Closure::bind(
            function (mixed $actual, mixed $expected): bool {
                return $this->rank5V6ValueMatches($actual, $expected);
            },
            $service,
            ReleaseReadinessService::class,
        );
        $this->assertNotNull($matches);

        $this->assertTrue($matches('1.00', 1));
        $this->assertTrue($matches('0.00', 0));
        $this->assertTrue($matches('1.65', 1.65));
        $this->assertTrue($matches('100', 100));
        $this->assertFalse($matches('1.01', 1));
        $this->assertFalse($matches('not-numeric', 1));
    }
}
