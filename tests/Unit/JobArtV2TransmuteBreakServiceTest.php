<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\HitResult;
use App\Services\JobArtBattleSupportService;
use App\Services\JobArtV2BreakDebuffService;
use App\Services\JobArtV2ConversionService;
use App\Services\JobArtV2EffectSemanticsResolver;
use App\Services\JobArtV2LoadoutPresenter;
use App\Services\JobArtV2PrototypeCatalog;
use App\Services\JobArtV2ResourceService;
use App\Services\JobArtV2SelectionService;
use App\Services\JobArtV2SpCostCalculator;
use App\Services\ResourceEvent;
use Tests\TestCase;

class JobArtV2TransmuteBreakServiceTest extends TestCase
{
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

    public function test_transmute_metadata_and_conversion_use_actual_post_cost_deltas_once(): void
    {
        $catalog = app(JobArtV2PrototypeCatalog::class);
        $job = $catalog->jobResourceMetadata(67);
        $this->assertSame(['catalyst', '触媒', 12, 1], [
            $job['resource_key'],
            $job['resource_name'],
            $job['resource_max_points'],
            $job['normal_attack_hit_gain_points'],
        ]);
        $this->assertSame('hp_sp_conversion_success', $catalog->artResourceMetadataForJobRank(67, 1)['resource_gain_event']);

        [$actor, $target, $state] = $this->battle(67, hp: 1_001, maxHp: 1_001, mp: 1_001, maxMp: 1_001);
        $rankOne = $this->art(67, 1, 'MAGICAL_DAMAGE_REWARD');
        $this->attachCurrent($actor, $rankOne);
        $resources = app(JobArtV2ResourceService::class);
        $spCost = app(JobArtV2SpCostCalculator::class)->forActor($actor, $rankOne);

        $resources->beginAction($actor, $state);
        $this->assertNull(app(JobArtV2SelectionService::class)->eligibilityFailureReason($actor, $state, $rankOne, $rankOne->id));
        $actor->mp -= $spCost;
        $result = $resources->applyJobArtCast($actor, $state, $rankOne);
        $duplicate = $resources->applyJobArtCast($actor, $state, $rankOne);

        $this->assertSame(ResourceEvent::HP_SP_CONVERSION_SUCCESS, $result->event);
        $this->assertSame(4, $result->delta);
        $this->assertFalse($duplicate->applied);
        $this->assertSame(950, $actor->hp);
        $this->assertSame(1_001, $actor->mp);
        $this->assertSame(4, $actor->getResource('catalyst'));
        $this->assertCount(1, $state->conversionResults());
        $conversion = $state->conversionResults()[0];
        $this->assertSame(51, $conversion->requestedHpCost);
        $this->assertSame(51, $conversion->actualHpLoss);
        $this->assertSame(51, $conversion->requestedSpGain);
        $this->assertSame($spCost, $conversion->actualSpGain);
        $this->assertTrue($conversion->success);
        $this->assertSame(0, $target->getResource('eclipse'));
    }

    public function test_transmute_eligibility_is_nonlethal_and_does_not_partially_pay_hp(): void
    {
        [$actor, , $state] = $this->battle(67, hp: 50, maxHp: 1_000, mp: 400, maxMp: 400);
        $rankOne = $this->art(67, 1, 'MAGICAL_DAMAGE_REWARD');
        $this->attachCurrent($actor, $rankOne);
        $selection = app(JobArtV2SelectionService::class);

        $this->assertSame(
            JobArtV2ConversionService::BLOCKED_BY_HP,
            $selection->eligibilityFailureReason($actor, $state, $rankOne, $rankOne->id),
        );

        $resources = app(JobArtV2ResourceService::class);
        $resources->beginAction($actor, $state);
        $before = [$actor->hp, $actor->mp];
        $resources->applyJobArtCast($actor, $state, $rankOne);
        $this->assertSame($before, [$actor->hp, $actor->mp]);
        $this->assertSame(0, $actor->getResource('catalyst'));
        $this->assertFalse($state->conversionResults()[0]->success);
    }

    public function test_transmute_rank_five_and_nine_only_spend_resource_and_never_convert(): void
    {
        [$actor, $target, $state] = $this->battle(67, hp: 1_000, maxHp: 1_000, mp: 300, maxMp: 400);
        $resources = app(JobArtV2ResourceService::class);
        $actor->configureResource('catalyst', 12);
        $actor->setResource('catalyst', 12);

        foreach ([[5, 8], [9, 0]] as [$rank, $remaining]) {
            $skill = $this->art(67, $rank, 'MAGICAL_DAMAGE_REWARD');
            $this->attachCurrent($actor, $skill);
            $resources->beginAction($actor, $state);
            $resources->applyJobArtCast($actor, $state, $skill);
            $this->assertSame($remaining, $actor->getResource('catalyst'));
            $this->assertSame(1_000, $actor->hp);
            $this->assertSame(300, $actor->mp);
            $actor->setResource('catalyst', 12);
        }

        $this->assertSame([], $state->conversionResults());

        $actor->setResource('catalyst', 0);
        $resources->beginAction($actor, $state);
        $normal = $resources->recordNormalAttackResolution($actor, $target, $state, HitResult::HIT);
        $this->assertSame(1, $normal->delta);
    }

    public function test_conversion_is_fail_closed_for_inherited_and_flag_off_paths(): void
    {
        foreach (['inherited', 'flag_off'] as $case) {
            [$actor, , $state] = $this->battle(67, hp: 1_000, maxHp: 1_000, mp: 300, maxMp: 400);
            $skill = $this->art(67, 1, 'MAGICAL_DAMAGE_REWARD');
            $actor->jobArtOrigins[(int) $skill->id] = $case === 'inherited' ? 'inherited' : 'current';
            if ($case === 'flag_off') {
                config(['battle.job_art_v2.resources' => false]);
            }

            app(JobArtV2ResourceService::class)->beginAction($actor, $state);
            app(JobArtV2ResourceService::class)->applyJobArtCast($actor, $state, $skill);
            $expectedResource = $case === 'inherited' ? 4 : 0;
            $this->assertSame([1_000, 300, $expectedResource], [$actor->hp, $actor->mp, $actor->getResource('catalyst')]);
            $this->assertSame([], $state->conversionResults());
            config(['battle.job_art_v2.resources' => true]);
        }
    }

    public function test_break_resource_uses_rank_one_hit_once_and_normal_hit(): void
    {
        [$actor, $target, $state] = $this->battle(68);
        $rankOne = $this->art(68, 1, 'DAMAGE_BUFF');
        $this->attachCurrent($actor, $rankOne);
        $resources = app(JobArtV2ResourceService::class);

        $resources->beginAction($actor, $state);
        $this->assertFalse($resources->applyJobArtCast($actor, $state, $rankOne)->applied);
        $hit = $resources->recordJobArtHit($actor, $state, $rankOne);
        $duplicate = $resources->recordJobArtHit($actor, $state, $rankOne);
        $this->assertSame(4, $hit->delta);
        $this->assertFalse($duplicate->applied);

        $resources->beginAction($actor, $state);
        $this->assertSame(1, $resources->recordNormalAttackResolution($actor, $target, $state, HitResult::HIT)->delta);
        $this->assertSame(5, $actor->getResource('break'));
    }

    public function test_break_debuff_applies_after_trigger_damage_and_expires_on_exact_round(): void
    {
        [$actor, $target, $state] = $this->battle(68, targetDef: 1_000, targetSpr: 800);
        $skill = $this->art(68, 5, 'DAMAGE_BUFF');
        $this->attachCurrent($actor, $skill);
        $service = app(JobArtV2BreakDebuffService::class);
        $state->turnCount = 1;
        app(JobArtV2ResourceService::class)->beginAction($actor, $state);

        $this->assertSame([1_000, 800], [$target->effectiveDef(), $target->effectiveSpr()]);
        $applied = $service->applyOnHit($actor, $target, $state, $skill, HitResult::HIT);
        $this->assertSame(JobArtV2BreakDebuffService::EVENT_APPLIED, $applied?->event);
        $this->assertSame([900, 720, 2], [$target->effectiveDef(), $target->effectiveSpr(), $target->breakDebuffState()?->remainingRounds]);

        $service->endRound($state);
        $this->assertSame(2, $target->breakDebuffState()?->remainingRounds);
        $state->turnCount = 2;
        $service->endRound($state);
        $this->assertSame(1, $target->breakDebuffState()?->remainingRounds);
        $state->turnCount = 3;
        $expired = $service->endRound($state);
        $this->assertNull($target->breakDebuffState());
        $this->assertSame(JobArtV2BreakDebuffService::EVENT_EXPIRED, $expired[0]->event);
        $this->assertSame([1_000, 800], [$target->effectiveDef(), $target->effectiveSpr()]);
    }

    public function test_break_stacking_replaces_refreshes_and_never_refreshes_from_weaker_effect(): void
    {
        [$actor, $target, $state] = $this->battle(68);
        $rankFive = $this->art(68, 5, 'DAMAGE_BUFF');
        $rankNine = $this->art(68, 9, 'DAMAGE_BUFF');
        $this->attachCurrent($actor, $rankFive);
        $this->attachCurrent($actor, $rankNine);
        $service = app(JobArtV2BreakDebuffService::class);

        $state->turnCount = 1;
        $this->begin($actor, $state);
        $service->applyOnHit($actor, $target, $state, $rankFive, HitResult::HIT);
        $state->turnCount = 2;
        $service->endRound($state);
        $this->assertSame(1, $target->breakDebuffState()?->remainingRounds);

        $this->begin($actor, $state);
        $replaced = $service->applyOnHit($actor, $target, $state, $rankNine, HitResult::HIT);
        $this->assertSame(JobArtV2BreakDebuffService::EVENT_REPLACED, $replaced?->event);
        $this->assertSame([0.15, 3], [$target->breakDebuffState()?->rate, $target->breakDebuffState()?->remainingRounds]);

        $state->turnCount = 3;
        $service->endRound($state);
        $this->assertSame(2, $target->breakDebuffState()?->remainingRounds);
        $this->begin($actor, $state);
        $kept = $service->applyOnHit($actor, $target, $state, $rankFive, HitResult::HIT);
        $this->assertSame(JobArtV2BreakDebuffService::EVENT_KEPT_STRONGER, $kept?->event);
        $this->assertSame(2, $target->breakDebuffState()?->remainingRounds);

        $this->begin($actor, $state);
        $refreshed = $service->applyOnHit($actor, $target, $state, $rankNine, HitResult::HIT);
        $this->assertSame(JobArtV2BreakDebuffService::EVENT_REFRESHED, $refreshed?->event);
        $this->assertSame(3, $target->breakDebuffState()?->remainingRounds);
    }

    public function test_break_requires_hit_and_uses_existing_boss_half_rule(): void
    {
        foreach ([HitResult::MISS, HitResult::EVADE] as $hitResult) {
            [$actor, $target, $state] = $this->battle(68);
            $skill = $this->art(68, 9, 'DAMAGE_BUFF');
            $this->attachCurrent($actor, $skill);
            $this->begin($actor, $state);
            $this->assertNull(app(JobArtV2BreakDebuffService::class)->applyOnHit($actor, $target, $state, $skill, $hitResult));
            $this->assertNull($target->breakDebuffState());
        }

        [$actor, $target] = $this->battle(68);
        $state = new BattleState($actor, $target, 'boss');
        $skill = $this->art(68, 9, 'DAMAGE_BUFF');
        $this->attachCurrent($actor, $skill);
        $this->begin($actor, $state);
        app(JobArtV2BreakDebuffService::class)->applyOnHit($actor, $target, $state, $skill, HitResult::HIT);
        $this->assertSame(0.075, $target->breakDebuffState()?->rate);
    }

    public function test_rank_five_and_nine_suppress_only_current_v2_legacy_self_buff(): void
    {
        $resolver = app(JobArtV2EffectSemanticsResolver::class);
        $support = app(JobArtBattleSupportService::class);

        foreach ([5, 9] as $rank) {
            $actor = $this->actor(68);
            $skill = $this->art(68, $rank, 'DAMAGE_BUFF');
            $this->attachCurrent($actor, $skill);
            $this->assertTrue($resolver->suppressesLegacySelfBuff($actor, $skill));
            $this->assertSame('PHYSICAL_DAMAGE', $support->skillForExecution($actor, $skill)->effect_template);

            $actor->jobArtOrigins[(int) $skill->id] = 'inherited';
            $this->assertFalse($resolver->suppressesLegacySelfBuff($actor, $skill));
            $this->assertSame('DAMAGE_BUFF', $support->skillForExecution($actor, $skill)->effect_template);
        }

        $rankOne = $this->art(68, 1, 'DAMAGE_BUFF');
        $actor = $this->actor(68);
        $this->attachCurrent($actor, $rankOne);
        $this->assertSame('DAMAGE_BUFF', $support->skillForExecution($actor, $rankOne)->effect_template);

        config(['battle.job_art_v2.resources' => false]);
        $rankFive = $this->art(68, 5, 'DAMAGE_BUFF');
        $this->attachCurrent($actor, $rankFive);
        $this->assertSame('DAMAGE_BUFF', $support->skillForExecution($actor, $rankFive)->effect_template);
    }

    public function test_transmute_and_break_ui_use_structured_metadata_without_rng(): void
    {
        $presenter = app(JobArtV2LoadoutPresenter::class);
        $transmute = $this->art(67, 1, 'MAGICAL_DAMAGE_REWARD');
        $transmute->setAttribute('job_art_origin', 'current');
        $break = $this->art(68, 5, 'DAMAGE_BUFF');
        $break->setAttribute('job_art_origin', 'current');

        mt_srand(23_067);
        $expected = mt_rand();
        mt_srand(23_067);
        $transmuteView = $presenter->forArt(67, $transmute);
        $breakView = $presenter->forArt(68, $break);
        $this->assertSame($expected, mt_rand());

        $this->assertContains('最大HPの5%を非致死で消費し、最大SPの5%を回復', $transmuteView['effect_texts']);
        $this->assertContains('実変換成立時：触媒+4', $transmuteView['effect_texts']);
        $this->assertContains('HIT後：対象のDEF/SPRを10%低下（2ラウンド）', $breakView['effect_texts']);
        $this->assertContains('低下はこの攻撃の次から有効', $breakView['effect_texts']);

        $this->assertStringContainsString('HPをSPへ変換', $presenter->recommendationsForCurrentJob(67, [$transmute])[0]['job_note']);
        $this->assertStringContainsString('長時間の崩し', $presenter->recommendationsForCurrentJob(68, [$break])[0]['job_note']);
    }

    public function test_hud_reads_conversion_and_break_state_structurally(): void
    {
        [$actor, $target, $state] = $this->battle(67, hp: 1_000, maxHp: 1_000, mp: 400, maxMp: 400);
        $skill = $this->art(67, 1, 'MAGICAL_DAMAGE_REWARD');
        $this->attachCurrent($actor, $skill);
        $resources = app(JobArtV2ResourceService::class);
        $resources->beginAction($actor, $state);
        $actor->mp -= app(JobArtV2SpCostCalculator::class)->forActor($actor, $skill);
        $resources->applyJobArtCast($actor, $state, $skill);
        $resources->finishAction($actor, $state);
        $hud = app(JobArtBattleSupportService::class)->battleHud($state);
        $this->assertSame('conversion', collect($hud['actions'][0]['changes'])->firstWhere('type', 'conversion')['type']);

        [$breaker, $breakTarget, $breakState] = $this->battle(68);
        $breakSkill = $this->art(68, 5, 'DAMAGE_BUFF');
        $this->attachCurrent($breaker, $breakSkill);
        $resources->beginAction($breaker, $breakState);
        $resources->applyJobArtCast($breaker, $breakState, $breakSkill);
        app(JobArtV2BreakDebuffService::class)->applyOnHit($breaker, $breakTarget, $breakState, $breakSkill, HitResult::HIT);
        $resources->finishAction($breaker, $breakState);
        $hud = app(JobArtBattleSupportService::class)->battleHud($breakState);
        $this->assertSame('break_debuff', collect($hud['actions'][0]['changes'])->firstWhere('type', 'break_debuff')['type']);
    }

    private function begin(BattleActor $actor, BattleState $state): void
    {
        app(JobArtV2ResourceService::class)->beginAction($actor, $state);
    }

    /** @return array{BattleActor, BattleActor, BattleState} */
    private function battle(
        int $jobId,
        int $hp = 10_000,
        int $maxHp = 10_000,
        int $mp = 1_000,
        int $maxMp = 1_000,
        int $targetDef = 1_000,
        int $targetSpr = 1_000,
    ): array {
        $actor = $this->actor($jobId, true, $hp, $maxHp, $mp, $maxMp);
        $target = $this->actor(null, false, 10_000, 10_000, 1_000, 1_000, $targetDef, $targetSpr);

        return [$actor, $target, new BattleState($actor, $target)];
    }

    private function actor(
        ?int $jobId,
        bool $isPlayer = true,
        int $hp = 10_000,
        int $maxHp = 10_000,
        int $mp = 1_000,
        int $maxMp = 1_000,
        int $def = 1_000,
        int $spr = 1_000,
    ): BattleActor {
        return new BattleActor($isPlayer ? 'player' : 'enemy', $isPlayer, [
            'hp' => $hp,
            'max_hp' => $maxHp,
            'mp' => $mp,
            'max_mp' => $maxMp,
            'str' => 1_000,
            'def' => $def,
            'agi' => 100,
            'mag' => 1_000,
            'spr' => $spr,
            'luk' => 100,
            'current_job_id' => $jobId,
        ]);
    }

    private function art(int $jobId, int $rank, string $template): Skill
    {
        $skill = new Skill([
            'name' => "job-{$jobId}-rank-{$rank}",
            'skill_type' => 'job_art',
            'job_id' => $jobId,
            'learn_rank' => $rank,
            'effect_template' => $template,
            'damage_type' => str_starts_with($template, 'MAGICAL') ? 'magical' : 'physical',
            'power' => [1 => 225, 5 => 285, 9 => 355][$rank],
            'power_multiplier' => [1 => 2.25, 5 => 2.85, 9 => 3.55][$rank],
            'hit_count' => 1,
            'max_uses_per_battle' => $rank === 9 ? 1 : null,
        ]);
        $skill->setAttribute('id', ($jobId * 100) + $rank);

        return $skill;
    }

    private function attachCurrent(BattleActor $actor, Skill $skill): void
    {
        $actor->jobArtOrigins[(int) $skill->id] = 'current';
        $actor->jobArtRates[(int) $skill->id] = 1.0;
    }
}
