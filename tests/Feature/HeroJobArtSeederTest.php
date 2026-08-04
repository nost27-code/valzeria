<?php

namespace Tests\Feature;

use Database\Seeders\JobArtSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HeroJobArtSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_three_job_arts_for_each_hero_job_without_special_skills(): void
    {
        $this->assertSame(10, DB::table('job_classes')->whereBetween('id', [70, 79])->count());
        DB::table('skills')->whereBetween('job_id', [70, 79])->where('skill_type', 'job_art')->delete();

        app(JobArtSeeder::class)->runForJobIds(range(70, 79));

        $heroArts = DB::table('skills')
            ->whereBetween('job_id', [70, 79])
            ->where('skill_type', 'job_art')
            ->orderBy('job_id')
            ->orderBy('learn_rank')
            ->get();

        $this->assertCount(30, $heroArts);
        foreach (range(70, 79) as $jobId) {
            $this->assertSame(
                [1, 5, 9],
                $heroArts->where('job_id', $jobId)->pluck('learn_rank')->map(fn (mixed $rank): int => (int) $rank)->all(),
            );
        }

        $this->assertSame(
            0,
            DB::table('skills')->whereBetween('job_id', [70, 79])->where('skill_type', 'special')->count(),
        );
    }

    public function test_scoped_seed_does_not_create_non_hero_job_arts(): void
    {
        $this->assertTrue(DB::table('job_classes')->where('id', 69)->exists());
        $this->assertTrue(DB::table('job_classes')->where('id', 70)->exists());
        DB::table('skills')->whereIn('job_id', [69, 70])->where('skill_type', 'job_art')->delete();

        app(JobArtSeeder::class)->runForJobIds([70]);

        $this->assertSame(3, DB::table('skills')->where('job_id', 70)->where('skill_type', 'job_art')->count());
        $this->assertSame(0, DB::table('skills')->where('job_id', 69)->where('skill_type', 'job_art')->count());
    }
}
