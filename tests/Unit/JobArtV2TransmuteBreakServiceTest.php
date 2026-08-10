<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\HitResult;
use App\Services\JobArtV2BreakDebuffService;
use App\Services\JobArtV2LoadoutPresenter;
use App\Services\JobArtV2ProgressionService;
use App\Services\JobArtV2PrototypeCatalog;
use App\Services\JobArtV2ResourceService;
use App\Services\JobArtV2RoleEffectService;
use App\Services\JobArtV2SelectionService;
use Tests\TestCase;

final class JobArtV2TransmuteBreakServiceTest extends TestCase
{
    private int $nextSkillId = 670_000;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'battle.job_art_v2.loadout_v2' => true,
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.normalized_sp' => true,
            'battle.job_art_v2.hit_resolution' => true,
            'battle.job_art_v2.damage_application' => true,
            'battle.job_art_v2.resources' => true,
            'battle.job_art_v2.fields' => true,
        ]);
    }

    public function test_crown_transmute_metadata_uses_suppression_costs_without_hp_sp_conversion(): void
    {
        $catalog = app(JobArtV2PrototypeCatalog::class);
        $rankOne = $catalog->artResourceMetadataForJobRank(67, 1);
        $rankNine = $catalog->artResourceMetadataForJobRank(67, 9);

        $this->assertSame(['catalyst', '触媒', 12, 1], [
            $catalog->jobResourceMetadata(67)['resource_key'],
            $catalog->jobResourceMetadata(67)['resource_name'],
            $catalog->jobResourceMetadata(67)['resource_max_points'],
            $catalog->jobResourceMetadata(67)['normal_attack_hit_gain_points'],
        ]);
        $this->assertSame(['consumer', 4, 4, 'job_art_cast'], [
            $rankOne['resource_role'],
            $rankOne['resource_cost_points'],
            $rankOne['minimum_resource_points'],
            $rankOne['resource_gain_event'],
        ]);
        $this->assertSame([8, 8], [$rankNine['resource_cost_points'], $rankNine['minimum_resource_points']]);

        [$actor, $target, $state] = $this->battle(67, 69);
        $skill = $this->art(67, 1, '金冠錬符', 'MAGICAL_DAMAGE_REWARD');
        $this->current($actor, $skill);
        $actor->configureResource('catalyst', 12);
        $actor->setResource('catalyst', 4);
        $before = [$actor->hp, $actor->mp];

        $this->begin($actor, $state);
        $this->assertNull(app(JobArtV2SelectionService::class)->eligibilityFailureReason($actor, $state, $skill, (int) $skill->id));
        $result = app(JobArtV2ResourceService::class)->applyJobArtCast($actor, $state, $skill);

        $this->assertSame(-4, $result->delta);
        $this->assertSame($before, [$actor->hp, $actor->mp]);
        $this->assertSame([], $state->conversionResults());
        $this->assertSame(0, $actor->getResource('catalyst'));
    }

    public function test_crown_transmute_rank_five_and_nine_spend_four_and_eight(): void
    {
        [$actor, , $state] = $this->battle(67, 69);
        $actor->configureResource('catalyst', 12);
        $resources = app(JobArtV2ResourceService::class);

        foreach ([[5, 4], [9, 8]] as [$rank, $cost]) {
            $skill = $this->art(67, $rank, $rank === 5 ? '金冠錬成' : '金冠ミダスフィールド', 'MAGICAL_DAMAGE_REWARD');
            $this->current($actor, $skill);
            $actor->setResource('catalyst', $cost);
            $this->begin($actor, $state);
            $result = $resources->applyJobArtCast($actor, $state, $skill);
            $this->assertSame(-$cost, $result->delta);
            $this->assertSame(0, $actor->getResource('catalyst'));
            $this->assertSame([], $state->conversionResults());
        }
    }

    public function test_crown_transmute_mechanic_is_same_lineage_only_and_cross_keeps_damage_only(): void
    {
        [$same, $sameTarget, $sameState] = $this->battle(67, 69);
        $sameArt = $this->art(67, 1, '金冠錬符', 'MAGICAL_DAMAGE_REWARD');
        $this->inherit($same, $sameArt);
        $same->configureResource('catalyst', 12);
        $same->setResource('catalyst', 4);
        $this->cast($same, $sameTarget, $sameState, $sameArt, applyResource: true);
        $this->assertNotSame([], $sameTarget->jobArtV2ProgressionState()->resourceSuppressions);
        $this->assertSame(0, $same->getResource('catalyst'));

        [$cross, $crossTarget, $crossState] = $this->battle(68, 69);
        $crossArt = clone $sameArt;
        $crossArt->setAttribute('id', ++$this->nextSkillId);
        $this->inherit($cross, $crossArt);
        $execution = $this->cast($cross, $crossTarget, $crossState, $crossArt, applyResource: true);
        $this->assertSame('MAGICAL_DAMAGE', $execution->effect_template);
        $this->assertSame(0, (int) $execution->gold_bonus_percent);
        $this->assertSame([], $crossTarget->jobArtV2ProgressionState()->resourceSuppressions);
        $this->assertSame(0, $cross->getResource('catalyst'));
    }

    public function test_crown_break_uses_marks_and_never_reuses_the_old_def_spr_debuff(): void
    {
        [$actor, $target, $state] = $this->battle(68, 66);
        $rankOne = $this->art(68, 1, '雷冠練気', 'DAMAGE_BUFF');
        $rankFive = $this->art(68, 5, '雷冠閃拳', 'DAMAGE_BUFF');
        $this->current($actor, $rankOne);
        $this->current($actor, $rankFive);

        $this->begin($actor, $state);
        app(JobArtV2ResourceService::class)->applyJobArtCast($actor, $state, $rankOne);
        app(JobArtV2ResourceService::class)->recordJobArtHit($actor, $state, $rankOne);
        app(JobArtV2RoleEffectService::class)->completeJobArtCast($actor, $target, $state, $rankOne, HitResult::HIT);

        $this->assertSame(4, $actor->getResource('break'));
        $this->assertSame(1, array_sum($target->jobArtV2ProgressionState()->breakMarks));
        $this->begin($actor, $state);
        $this->assertNull(app(JobArtV2BreakDebuffService::class)->applyOnHit($actor, $target, $state, $rankFive, HitResult::HIT));
        $this->assertNull($target->breakDebuffState());
    }

    public function test_cleansing_crown_break_marks_grants_zanshin_once_and_reconnects_on_hit(): void
    {
        [$actor, $target, $state] = $this->battle(68, 66);
        $rankOne = $this->art(68, 1, '雷冠練気', 'DAMAGE_BUFF');
        $rankFive = $this->art(68, 5, '雷冠閃拳', 'DAMAGE_BUFF');
        $this->current($actor, $rankOne);
        $this->current($actor, $rankFive);
        $this->cast($actor, $target, $state, $rankOne);

        $progression = app(JobArtV2ProgressionService::class);
        $this->assertSame(['break_mark'], $progression->purgeBreakMarks($target));
        $this->assertTrue($actor->jobArtV2ProgressionState()->zanshinAvailable);
        $this->assertSame([], $progression->purgeBreakMarks($target));

        $this->cast($actor, $target, $state, $rankFive);
        $this->assertFalse($actor->jobArtV2ProgressionState()->zanshinAvailable);
        $this->assertSame(1, array_sum($target->jobArtV2ProgressionState()->breakMarks));
    }

    public function test_transmute_and_break_ui_copy_matches_runtime_without_rng(): void
    {
        $transmute = $this->art(67, 1, '金冠錬符', 'MAGICAL_DAMAGE_REWARD');
        $transmute->setAttribute('job_art_origin', 'current');
        $break = $this->art(68, 5, '雷冠閃拳', 'DAMAGE_BUFF');
        $break->setAttribute('job_art_origin', 'current');

        mt_srand(67_680);
        $expected = mt_rand();
        mt_srand(67_680);
        $transmuteView = app(JobArtV2LoadoutPresenter::class)->forArt(67, $transmute);
        $breakView = app(JobArtV2LoadoutPresenter::class)->forArt(68, $break);

        $this->assertSame($expected, mt_rand());
        $this->assertContains('対象の次回resource実獲得を半減', $transmuteView['effect_texts']);
        $this->assertContains('浄化された冠位由来の崩し印を残心として1戦1回保持', $breakView['effect_texts']);
        $this->assertSame('MAGICAL_DAMAGE', $transmuteView['effect_template']);
        $this->assertSame('PHYSICAL_DAMAGE', $breakView['effect_template']);
    }

    /** @return array{BattleActor, BattleActor, BattleState} */
    private function battle(int $actorJobId, int $targetJobId): array
    {
        $actor = $this->actor('actor', true, $actorJobId);
        $target = $this->actor('target', false, $targetJobId);

        return [$actor, $target, new BattleState($actor, $target)];
    }

    private function actor(string $name, bool $isPlayer, int $jobId): BattleActor
    {
        return new BattleActor($name, $isPlayer, [
            'hp' => 1_000,
            'max_hp' => 1_000,
            'mp' => 500,
            'max_mp' => 500,
            'str' => 250,
            'def' => 100,
            'agi' => 100,
            'mag' => 250,
            'spr' => 100,
            'luk' => 100,
            'current_job_id' => $jobId,
        ]);
    }

    private function art(int $jobId, int $rank, string $name, string $template): Skill
    {
        $skill = new Skill([
            'name' => $name,
            'skill_type' => 'job_art',
            'job_id' => $jobId,
            'learn_rank' => $rank,
            'effect_template' => $template,
            'damage_type' => str_contains($template, 'MAGICAL') ? 'magical' : 'physical',
            'power' => match ($rank) { 1 => 225, 5 => 285, default => 455 },
            'power_multiplier' => match ($rank) { 1 => 2.25, 5 => 2.85, default => 4.55 },
            'hit_count' => 1,
            'activation_rate' => 100,
            'gold_bonus_percent' => 20,
            'drop_bonus_percent' => 10,
        ]);
        $skill->setAttribute('id', ++$this->nextSkillId);

        return $skill;
    }

    private function current(BattleActor $actor, Skill $skill): void
    {
        $actor->jobArtOrigins[(int) $skill->id] = 'current';
        $actor->jobArtRates[(int) $skill->id] = 1.0;
    }

    private function inherit(BattleActor $actor, Skill $skill): void
    {
        $actor->jobArtOrigins[(int) $skill->id] = 'inherited';
        $actor->jobArtRates[(int) $skill->id] = 1.0;
    }

    private function begin(BattleActor $actor, BattleState $state): void
    {
        $sourceActionId = app(JobArtV2ResourceService::class)->beginAction($actor, $state);
        $this->assertNotNull($sourceActionId);
        app(JobArtV2RoleEffectService::class)->beginAction($actor, $state, $sourceActionId);
    }

    private function cast(
        BattleActor $actor,
        BattleActor $target,
        BattleState $state,
        Skill $source,
        bool $applyResource = false,
    ): Skill {
        $this->begin($actor, $state);
        $execution = clone $source;
        $roles = app(JobArtV2RoleEffectService::class);
        $roles->applyForExecution($actor, $target, $state, $source, $execution);
        if ($applyResource) {
            app(JobArtV2ResourceService::class)->applyJobArtCast($actor, $state, $source);
        }
        $roles->beginJobArtCast($actor, $state, $source);
        $roles->completeJobArtCast($actor, $target, $state, $source, HitResult::HIT);

        return $execution;
    }
}
