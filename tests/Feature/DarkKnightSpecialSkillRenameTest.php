<?php

namespace Tests\Feature;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\BattleService;
use Database\Seeders\JobArtSeeder;
use Database\Seeders\SkillSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
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
        $this->assertSame(2.20, (float) ($specialSkill['power_multiplier'] ?? 0));
        $this->assertSame(0, (int) ($specialSkill['self_damage_percent'] ?? 0));
        $this->assertSame(10, (int) ($specialSkill['enemy_atk_down_percent'] ?? 0));
        $this->assertSame('攻撃依存の2.20倍物理攻撃。敵攻撃を10%低下', $specialSkill['description'] ?? null);
        $this->assertSame('暗黒剣', $jobArt['name'] ?? null);
        $this->assertSame('physical', $jobArt['damage_type'] ?? null);
        $this->assertSame(100, (int) ($jobArt['power_hint'] ?? 0));
        $this->assertSame(0.35, (float) ($jobArt['drain_hp_rate'] ?? 0));
        $this->assertSame(5, (int) ($jobArt['self_damage_percent'] ?? 0));
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

        $migration = $this->renameMigration();
        $migration->up();

        $this->assertSame('冥血斬', DB::table('skills')->where('id', $specialSkill->id)->value('name'));
        $this->assertSame('暗黒剣', DB::table('skills')->where('id', $jobArt->id)->value('name'));
        $this->assertSame(2.20, (float) DB::table('skills')->where('id', $specialSkill->id)->value('power_multiplier'));
        $this->assertSame(0, (int) DB::table('skills')->where('id', $specialSkill->id)->value('self_damage_percent'));
        $this->assertSame(10, (int) DB::table('skills')->where('id', $specialSkill->id)->value('enemy_atk_down_percent'));

        $migration->down();

        $this->assertSame('暗黒剣', DB::table('skills')->where('id', $specialSkill->id)->value('name'));
        $this->assertSame('暗黒剣', DB::table('skills')->where('id', $jobArt->id)->value('name'));
    }

    public function test_job_art_seeder_keeps_other_drain_arts_magical(): void
    {
        app(JobArtSeeder::class)->runForJobIds([19, 30]);

        $this->assertSame('magical', DB::table('skills')
            ->where('job_id', 19)
            ->where('skill_type', 'job_art')
            ->where('learn_rank', 5)
            ->value('damage_type'));
        $this->assertSame('physical', DB::table('skills')
            ->where('job_id', 30)
            ->where('skill_type', 'job_art')
            ->where('learn_rank', 5)
            ->value('damage_type'));
    }

    public function test_balance_migration_updates_both_dark_knight_skills_and_is_reversible(): void
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

        DB::table('skills')->where('id', $specialSkill->id)->update([
            'power_multiplier' => 2.35,
            'self_damage_percent' => 7,
            'enemy_atk_down_percent' => 0,
            'description' => '2.35倍攻撃。反動で最大HPの7%ダメージ',
        ]);
        DB::table('skills')->where('id', $jobArt->id)->update([
            'damage_type' => 'magical',
            'power' => 185,
            'power_multiplier' => 1.85,
            'drain_hp_rate' => 0.35,
            'self_damage_percent' => 5,
            'description' => '単体大ダメージ＋与ダメの一部を吸収。反動で最大HP5%ダメージ',
            'memo' => '単体大ダメージ＋与ダメの一部を吸収。反動で最大HP5%ダメージ',
        ]);

        $migration = $this->balanceMigration();
        $migration->up();

        $updatedSpecial = DB::table('skills')->where('id', $specialSkill->id)->first();
        $updatedJobArt = DB::table('skills')->where('id', $jobArt->id)->first();

        $this->assertSame('冥血斬', $updatedSpecial->name);
        $this->assertSame(2.20, (float) $updatedSpecial->power_multiplier);
        $this->assertSame(0, (int) $updatedSpecial->self_damage_percent);
        $this->assertSame(10, (int) $updatedSpecial->enemy_atk_down_percent);
        $this->assertSame('ATK依存の2.20倍物理攻撃。敵ATKを10%低下', $updatedSpecial->description);
        $this->assertSame('暗黒剣', $updatedJobArt->name);
        $this->assertSame('physical', $updatedJobArt->damage_type);
        $this->assertSame(185, (int) $updatedJobArt->power);
        $this->assertSame(1.85, (float) $updatedJobArt->power_multiplier);
        $this->assertSame(0.35, (float) $updatedJobArt->drain_hp_rate);
        $this->assertSame(5, (int) $updatedJobArt->self_damage_percent);
        $this->assertSame('ATK依存の1.85倍物理攻撃。与ダメージの35%を吸収し、反動で最大HP5%ダメージ', $updatedJobArt->description);

        $migration->down();

        $restoredSpecial = DB::table('skills')->where('id', $specialSkill->id)->first();
        $restoredJobArt = DB::table('skills')->where('id', $jobArt->id)->first();

        $this->assertSame('冥血斬', $restoredSpecial->name);
        $this->assertSame(2.35, (float) $restoredSpecial->power_multiplier);
        $this->assertSame(7, (int) $restoredSpecial->self_damage_percent);
        $this->assertSame(0, (int) $restoredSpecial->enemy_atk_down_percent);
        $this->assertSame('2.35倍攻撃。反動で最大HPの7%ダメージ', $restoredSpecial->description);
        $this->assertSame('magical', $restoredJobArt->damage_type);
        $this->assertSame(1.85, (float) $restoredJobArt->power_multiplier);
        $this->assertSame(0.35, (float) $restoredJobArt->drain_hp_rate);
        $this->assertSame(5, (int) $restoredJobArt->self_damage_percent);
    }

    public function test_recoil_free_special_skill_does_not_reduce_the_attackers_hp(): void
    {
        app(SkillSeeder::class)->run();

        $specialSkill = Skill::query()
            ->where('job_id', 30)
            ->where('skill_type', 'special')
            ->firstOrFail();
        $attacker = $this->battleActor('暗黒騎士', true, 10_000, 30, 1_000, 100);
        $defender = $this->battleActor('検証対象', false, 1_000_000, null, 100, 50);
        $state = new BattleState($attacker, $defender, 'pve');
        $method = new ReflectionMethod(BattleService::class, 'executeSkillAction');

        $method->invoke(app(BattleService::class), $attacker, $defender, $state, $specialSkill);

        $this->assertSame(10_000, $attacker->hp);
        $this->assertLessThan(1_000_000, $defender->hp);
        $this->assertSame(90, $defender->str);
        $this->assertFalse(collect($state->logs)->contains(
            fn (string $log): bool => str_contains(strip_tags($log), '反動'),
        ));
    }

    public function test_fresh_seeded_rank5_v6_dark_sword_uses_100_percent_physical_drain_and_recoil_while_runtime_gate_is_off(): void
    {
        config([
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.hit_resolution' => true,
            'battle.job_art_v2.damage_application' => true,
            'battle.job_art_v2.resources' => true,
            'battle.job_art_v2.rank5_v6' => false,
        ]);
        app(JobArtSeeder::class)->runForJobIds([30]);

        $jobArt = Skill::query()
            ->where('job_id', 30)
            ->where('skill_type', 'job_art')
            ->where('learn_rank', 5)
            ->firstOrFail();
        $jobArt->setAttribute('sure_hit', true);
        $attacker = $this->battleActor('暗黒騎士', true, 10_000, 30, 1_000, 100);
        $attacker->hp = 5_000;
        $attacker->mag = 1;
        $defender = $this->battleActor('検証対象', false, 1_000_000, null, 100, 50);
        $defender->spr = 500;
        $state = new BattleState($attacker, $defender, 'pve');
        $method = new ReflectionMethod(BattleService::class, 'executeJobArtAction');

        $method->invoke(app(BattleService::class), $attacker, $defender, $state, $jobArt);

        $this->assertSame('暗黒剣', $jobArt->name);
        $this->assertSame('physical', $jobArt->damage_type);
        $this->assertSame(1.0, (float) $jobArt->power_multiplier);
        $this->assertSame(0.35, (float) $jobArt->drain_hp_rate);
        $this->assertSame(5, (int) $jobArt->self_damage_percent);
        $this->assertGreaterThanOrEqual(800, 1_000_000 - $defender->hp);
        $this->assertGreaterThan(5_000, $attacker->hp);
        $this->assertTrue(collect($state->logs)->contains(
            fn (string $log): bool => str_contains(strip_tags($log), '吸収'),
        ));
        $this->assertTrue(collect($state->logs)->contains(
            fn (string $log): bool => str_contains(strip_tags($log), '反動'),
        ));
    }

    private function renameMigration(): object
    {
        return require base_path('database/migrations/2026_08_12_030000_rename_dark_knight_special_skill.php');
    }

    private function balanceMigration(): object
    {
        return require base_path('database/migrations/2026_08_12_040000_rebalance_dark_knight_skills.php');
    }

    private function battleActor(
        string $name,
        bool $isPlayer,
        int $hp,
        ?int $jobId,
        int $attack,
        int $defense,
    ): BattleActor {
        return new BattleActor($name, $isPlayer, [
            'hp' => $hp,
            'max_hp' => $hp,
            'mp' => 1_000,
            'max_mp' => 1_000,
            'str' => $attack,
            'def' => $defense,
            'agi' => 100,
            'mag' => 100,
            'spr' => $defense,
            'luk' => 100,
            'current_job_id' => $jobId,
        ]);
    }
}
