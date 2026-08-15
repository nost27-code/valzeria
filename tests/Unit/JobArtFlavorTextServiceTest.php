<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\BattleService;
use App\Services\JobArtBattleSupportService;
use App\Services\JobArtFlavorTextService;
use ReflectionMethod;
use Tests\TestCase;

class JobArtFlavorTextServiceTest extends TestCase
{
    public function test_off_returns_the_current_master_flavor_text_unchanged(): void
    {
        config(['battle.job_art_v2.flavor_rewrite' => false]);
        $skill = $this->bloodRoar();

        $this->assertSame([
            'activation_phrase' => '「受け止めてみろ！」',
            'activation_description' => '{user}は全身の力を込め、《{skill}》を{target}へ叩き込んだ！',
        ], app(JobArtFlavorTextService::class)->resolve($skill));
    }

    public function test_on_resolves_the_rewrite_by_job_rank_and_name(): void
    {
        config(['battle.job_art_v2.flavor_rewrite' => true]);

        $this->assertSame([
            'activation_phrase' => '「この血は、まだ燃え尽きていない！」',
            'activation_description' => '{user}は己の血潮を猛る力へ変え、《{skill}》の咆哮とともに全身へ巡らせた！',
        ], app(JobArtFlavorTextService::class)->resolve($this->bloodRoar()));
    }

    public function test_on_does_not_rewrite_non_job_art_skills(): void
    {
        config(['battle.job_art_v2.flavor_rewrite' => true]);
        $skill = $this->bloodRoar();
        $skill->skill_type = 'special';

        $this->assertSame([
            'activation_phrase' => '「受け止めてみろ！」',
            'activation_description' => '{user}は全身の力を込め、《{skill}》を{target}へ叩き込んだ！',
        ], app(JobArtFlavorTextService::class)->resolve($skill));
    }

    public function test_activation_title_classes_follow_the_job_art_stage(): void
    {
        $service = app(JobArtFlavorTextService::class);
        $expectedClasses = [
            1 => 'battle-log-job-art-title battle-log-job-art-title--starter',
            5 => 'battle-log-job-art-title battle-log-job-art-title--combo',
            9 => 'battle-log-job-art-title battle-log-job-art-title--ultimate',
        ];

        foreach ($expectedClasses as $rank => $expectedClass) {
            $skill = $this->bloodRoar();
            $skill->learn_rank = $rank;

            $this->assertSame($expectedClass, $service->activationTitleClass($skill));
        }
    }

    public function test_activation_title_css_uses_distinct_stage_sizes_and_colors(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression(
            '/\.battle-log-job-art-title--starter\s*\{[^}]*color:\s*#047857;[^}]*font-size:\s*1em;/s',
            $css,
        );
        $this->assertMatchesRegularExpression(
            '/\.battle-log-job-art-title--combo\s*\{[^}]*color:\s*#0369a1;[^}]*font-size:\s*1\.15em;/s',
            $css,
        );
        $this->assertMatchesRegularExpression(
            '/\.battle-log-job-art-title--ultimate\s*\{[^}]*color:\s*#92400e;[^}]*font-size:\s*1\.35em;/s',
            $css,
        );
    }

    public function test_catalog_exactly_covers_the_current_282_job_art_master_rows(): void
    {
        $masterRows = json_decode(
            (string) file_get_contents(database_path('data/job_arts.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $rewrites = app(JobArtFlavorTextService::class)->allRewrites();

        $masterKeys = array_map(
            fn (array $row): string => $this->identity($row),
            $masterRows,
        );
        $rewriteKeys = array_map(
            fn (array $row): string => $this->identity($row),
            $rewrites,
        );
        sort($masterKeys);
        sort($rewriteKeys);

        $this->assertCount(282, $rewrites);
        $this->assertSame($masterKeys, $rewriteKeys);
        $this->assertCount(282, array_unique(array_column($rewrites, 'activation_phrase')));
        $this->assertCount(282, array_unique(array_column($rewrites, 'activation_description')));

        foreach ($rewrites as $rewrite) {
            $this->assertStringContainsString('{user}', $rewrite['activation_description']);
            $this->assertStringContainsString('{skill}', $rewrite['activation_description']);
            preg_match_all(
                '/\{[^}]+\}/u',
                $rewrite['activation_phrase'].$rewrite['activation_description'],
                $placeholderMatches,
            );
            $this->assertSame(
                [],
                array_values(array_diff(array_unique($placeholderMatches[0]), ['{user}', '{target}', '{skill}'])),
                $this->identity($rewrite),
            );
        }
    }

    public function test_on_rewrite_is_used_by_both_job_art_battle_log_paths(): void
    {
        config(['battle.job_art_v2.flavor_rewrite' => true]);
        $skill = $this->bloodRoar();
        $skill->setAttribute('id', 1401);
        $attacker = new BattleActor('かんりにん', true, [
            'hp' => 100,
            'max_hp' => 100,
            'mp' => 100,
            'max_mp' => 100,
        ]);
        $defender = new BattleActor('中枢守護機', false, [
            'hp' => 100,
            'max_hp' => 100,
        ]);
        $attacker->jobArtOrigins[1401] = 'inherited';

        $supportLog = app(JobArtBattleSupportService::class)->activationLog($attacker, $defender, $skill);
        $this->assertStringContainsString('battle-log-job-art-title--starter', $supportLog);
        $this->assertStringContainsString('《血潮の咆哮》が発動！', $supportLog);
        $this->assertStringNotContainsString('【継承奥義】', $supportLog);
        $this->assertStringNotContainsString('【奥義】', $supportLog);
        $this->assertStringContainsString('「この血は、まだ燃え尽きていない！」', $supportLog);
        $this->assertStringContainsString('かんりにんは己の血潮を猛る力へ変え、《血潮の咆哮》の咆哮とともに全身へ巡らせた！', $supportLog);
        $this->assertStringNotContainsString('中枢守護機へ叩き込んだ', $supportLog);

        $battleService = app(BattleService::class);
        $activationLog = new ReflectionMethod($battleService, 'jobArtActivationLog');
        $pveLog = (string) $activationLog->invoke(
            $battleService,
            $attacker,
            $defender,
            $skill,
        );

        $this->assertStringContainsString('battle-log-job-art-title--starter', $pveLog);
        $this->assertStringContainsString('《血潮の咆哮》が発動！', $pveLog);
        $this->assertStringNotContainsString('【継承奥義】', $pveLog);
        $this->assertStringNotContainsString('【奥義】', $pveLog);
        $this->assertStringContainsString('「この血は、まだ燃え尽きていない！」', $pveLog);
        $this->assertStringContainsString('かんりにんは己の血潮を猛る力へ変え、《血潮の咆哮》の咆哮とともに全身へ巡らせた！', $pveLog);
        $this->assertStringNotContainsString('中枢守護機へ叩き込んだ', $pveLog);
    }

    private function bloodRoar(): Skill
    {
        return new Skill([
            'job_id' => 14,
            'learn_rank' => 1,
            'name' => '血潮の咆哮',
            'skill_type' => 'job_art',
            'activation_phrase' => '「受け止めてみろ！」',
            'activation_description' => '{user}は全身の力を込め、《{skill}》を{target}へ叩き込んだ！',
        ]);
    }

    /** @param array{job_id: int, learn_rank: int, name: string} $row */
    private function identity(array $row): string
    {
        return $row['job_id'].':'.$row['learn_rank'].':'.$row['name'];
    }
}
