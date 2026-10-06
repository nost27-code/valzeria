<?php

namespace Tests\Feature;

use App\Models\Skill;
use App\Services\HeroTrialProfileService;
use App\Services\ReleaseReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecondWaveHeroTrialReleaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_hero_trial_hall_has_the_approved_three_power_tiers_in_release_order(): void
    {
        $tiers = config('hero_trials.hall_tiers');

        $this->assertSame(['first_wave', 'second_wave', 'final_wave'], array_keys($tiers));
        $this->assertSame([350_000, 400_000, 450_000], array_column($tiers, 'recommended_power'));
        $this->assertSame([false, false, false], array_column($tiers, 'is_planned'));
        $this->assertSame(
            array_keys(config('hero_trials.hall_cards')),
            collect($tiers)->flatMap(static fn (array $tier): array => $tier['trial_keys'])->all(),
        );
        $this->assertSame(
            array_keys(config('hero_trials.released_trials')),
            collect($tiers)
                ->reject(static fn (array $tier): bool => $tier['is_planned'])
                ->flatMap(static fn (array $tier): array => $tier['trial_keys'])
                ->all(),
        );
    }

    public function test_second_wave_trial_masters_are_installed_while_both_gates_stay_off_by_default(): void
    {
        $this->assertFalse((bool) config('extra_content.contents.hero_trials.default_enabled'));
        $this->assertFalse((bool) config('extra_content.contents.hero_trials_second_wave.default_enabled'));

        foreach ([
            [88, 'heavenly_machina_hero_trial', 74, 'heavenly_machina_chancellor', 65, '074'],
            [89, 'sanctuary_hero_trial', 75, 'sanctuary_judge', 66, '075'],
            [90, 'storm_hero_trial', 78, 'storm_overlord', 68, '078'],
            [91, 'silver_guardian_hero_trial', 79, 'silver_guardian_king', 69, '079'],
        ] as [$areaId, $slug, $heroJobId, $heroJobKey, $requiredJobId, $assetNumber]) {
            $this->assertDatabaseHas('areas', [
                'id' => $areaId,
                'city_id' => 10,
                'slug' => $slug,
                'area_kind' => 'hero_trial',
                'is_published' => false,
            ]);
            $this->assertDatabaseHas('job_classes', [
                'id' => $heroJobId,
                'key' => $heroJobKey,
                'is_active' => true,
                'is_hidden' => true,
            ]);
            $this->assertDatabaseHas('job_requirements', [
                'job_id' => $heroJobId,
                'requirement_type' => 'master_job',
                'required_job_id' => $requiredJobId,
            ]);
            $this->assertGreaterThanOrEqual(
                3,
                Skill::query()
                    ->where('job_id', $heroJobId)
                    ->where('skill_type', 'job_art')
                    ->count(),
            );
            $this->assertFileExists(public_path("images/symbol/hero_trial_{$assetNumber}.webp"));
            $this->assertFileExists(public_path("images/jobbadge/jobbadge_{$assetNumber}.webp"));
            $this->assertFileExists(public_path("images/job_portrait/hero_trial_{$assetNumber}.webp"));
        }

        foreach ([727, 728, 729, 730, 733, 734] as $enemyImageNumber) {
            $this->assertFileExists(public_path("images/enemy/enemy_{$enemyImageNumber}.webp"));
        }

        $this->assertSame([], app(ReleaseReadinessService::class)->contentIssues('hero_trials_second_wave'));
        $this->assertSame([], app(ReleaseReadinessService::class)->contentIssues('hero_trials'));
    }

    public function test_second_wave_profiles_have_the_approved_trials_and_distinct_mechanics(): void
    {
        $profiles = app(HeroTrialProfileService::class);

        $machina = $profiles->enemies('heavenly_machina_chancellor_balanced');
        $this->assertSame(
            ['天機偵察機シーカー', '天機重装機バスティオン', '天機演算核オラクル'],
            $machina->pluck('name')->all(),
        );
        $this->assertSame(
            ['images/enemy/enemy_727.webp', 'images/enemy/enemy_728.webp', 'images/enemy/enemy_729.webp'],
            collect($profiles->profile('heavenly_machina_chancellor_balanced')['phases'])->pluck('image_path')->all(),
        );
        $this->assertSame(['magical_slow', 'magical_multi_hit'], $machina[0]->actions->pluck('action_type')->all());
        $this->assertSame(['def_pierce', 'charge'], $machina[1]->actions->pluck('action_type')->all());
        $this->assertSame(['magical_spr_down', 'magical'], $machina[2]->actions->pluck('action_type')->all());
        $this->assertSame([42_000, 56_400, 76_800], $machina->pluck('max_hp')->all());
        $this->assertSame([10_800, 3_600, 15_000], $machina->pluck('mag')->all());

        $sanctuary = $profiles->enemies('sanctuary_judge_balanced')->sole();
        $this->assertSame('天秤聖獣ユスティア', $sanctuary->name);
        $this->assertSame('magical', $sanctuary->normal_attack_type);
        $this->assertSame(
            ['magical_spr_down', 'recovery_block', 'magical'],
            $sanctuary->actions->pluck('action_type')->all(),
        );

        $storm = $profiles->enemies('storm_overlord_balanced')->sole();
        $this->assertSame('雷嵐神獣テンペスタ', $storm->name);
        $this->assertSame(12_286, $storm->agi);
        $this->assertSame(
            ['multi_hit', 'critical_strike', 'self_buff'],
            $storm->actions->pluck('action_type')->all(),
        );

        $silver = $profiles->enemies('silver_guardian_king_balanced')->sole();
        $this->assertSame('白銀城塞アルジェオン', $silver->name);
        $this->assertSame(15_429, $silver->def);
        $this->assertSame(13_143, $silver->spr);
        $this->assertSame(
            ['def_pierce', 'current_hp_percent', 'charge'],
            $silver->actions->pluck('action_type')->all(),
        );

        $this->assertSame(['機械', '飛行', '人型', '魔法型'], $profiles->speciesLabels('heavenly_machina_chancellor_balanced'));
        $this->assertSame(['獣', '精霊'], $profiles->speciesLabels('sanctuary_judge_balanced'));
        $this->assertSame(['獣', '精霊'], $profiles->speciesLabels('storm_overlord_balanced'));
        $this->assertSame(['機械', '精霊'], $profiles->speciesLabels('silver_guardian_king_balanced'));

        $this->assertSame(21.5, $profiles->profile('heavenly_machina_chancellor_balanced')['benchmark']['pass_rate']);
        $this->assertSame(24.7, $profiles->profile('sanctuary_judge_balanced')['benchmark']['pass_rate']);
        $this->assertSame(22.7, $profiles->profile('storm_overlord_balanced')['benchmark']['pass_rate']);
        $this->assertSame(14.9, $profiles->profile('silver_guardian_king_balanced')['benchmark']['pass_rate']);
    }
}
