<?php

namespace Tests\Feature;

use Database\Seeders\JobArtSeeder;
use Database\Seeders\SkillSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DarkKnightSpecialSkillRenameTest extends TestCase
{
    use RefreshDatabase;

    public function test_master_keeps_the_special_and_job_art_names_distinct(): void
    {
        $specialSkills = require base_path('database/data/job_special_skills.php');
        $specialSkill = collect($specialSkills)->firstWhere('job_key', 'dark_knight');

        $jobArts = json_decode(
            (string) file_get_contents(base_path('database/data/job_arts.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $jobArt = collect($jobArts)->first(
            fn (array $row): bool => (int) ($row['job_id'] ?? 0) === 30
                && (int) ($row['learn_rank'] ?? 0) === 5,
        );

        $this->assertSame('冥血斬', $specialSkill['special_name'] ?? null);
        $this->assertSame('暗黒剣', $jobArt['name'] ?? null);
    }

    public function test_migration_renames_only_the_existing_special_skill_and_is_reversible(): void
    {
        app(SkillSeeder::class)->run();
        app(JobArtSeeder::class)->runForJobIds([30]);

        $specialSkill = DB::table('skills')
            ->where('job_id', 30)
            ->where('skill_type', 'special')
            ->first();
        $jobArt = DB::table('skills')
            ->where('job_id', 30)
            ->where('skill_type', 'job_art')
            ->where('learn_rank', 5)
            ->first();

        $this->assertNotNull($specialSkill);
        $this->assertNotNull($jobArt);
        $this->assertSame('冥血斬', $specialSkill->name);
        $this->assertSame('暗黒剣', $jobArt->name);

        DB::table('skills')->where('id', $specialSkill->id)->update(['name' => '暗黒剣']);

        $migration = $this->migration();
        $migration->up();

        $this->assertSame('冥血斬', DB::table('skills')->where('id', $specialSkill->id)->value('name'));
        $this->assertSame('暗黒剣', DB::table('skills')->where('id', $jobArt->id)->value('name'));
        $this->assertSame(2.35, (float) DB::table('skills')->where('id', $specialSkill->id)->value('power_multiplier'));
        $this->assertSame(7, (int) DB::table('skills')->where('id', $specialSkill->id)->value('self_damage_percent'));

        $migration->down();

        $this->assertSame('暗黒剣', DB::table('skills')->where('id', $specialSkill->id)->value('name'));
        $this->assertSame('暗黒剣', DB::table('skills')->where('id', $jobArt->id)->value('name'));
    }

    private function migration(): object
    {
        return require base_path('database/migrations/2026_08_12_030000_rename_dark_knight_special_skill.php');
    }
}
