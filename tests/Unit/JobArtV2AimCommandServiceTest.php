<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\ActionResolver;
use App\Services\Battle\BattleActionResult;
use App\Services\Battle\BattleActionType;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageCalculator;
use App\Services\Battle\HitResult;
use App\Services\FieldState;
use App\Services\JobArtV2ActiveEvasionProvider;
use App\Services\JobArtV2FeatureGate;
use App\Services\JobArtV2HitRandomSource;
use App\Services\JobArtV2PrototypeCatalog;
use App\Services\JobArtV2ResourceService;
use App\Services\JobArtV2SpPressureService;
use App\Services\ResourceEvent;
use Tests\TestCase;

class JobArtV2AimCommandServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.hit_resolution' => true,
            'battle.job_art_v2.damage_application' => true,
            'battle.job_art_v2.resources' => true,
            'battle.job_art_v2.fields' => true,
        ]);
    }

    public function test_aim_resource_uses_cast_hit_miss_and_evade_events_without_extra_randomness(): void
    {
        [$actor, $target, $state] = $this->battle(65);
        $resources = app(JobArtV2ResourceService::class);

        $resources->beginAction($actor, $state);
        $this->assertSame(4, $resources->applyJobArtCast($actor, $state, $this->art(65, 1))->delta);
        $resources->finishAction($actor, $state);

        mt_srand(6501);
        $expectedNext = mt_rand();
        mt_srand(6501);
        $resources->beginAction($actor, $state);
        $hit = $resources->recordNormalAttackResolution($actor, $target, $state, HitResult::HIT);
        $resources->finishAction($actor, $state);
        $this->assertSame($expectedNext, mt_rand());
        $this->assertSame(ResourceEvent::NORMAL_ATTACK_HIT, $hit->event);
        $this->assertSame(1, $hit->delta);

        $resources->beginAction($actor, $state);
        $miss = $resources->recordNormalAttackResolution($actor, $target, $state, HitResult::MISS);
        $resources->finishAction($actor, $state);
        $this->assertSame(ResourceEvent::NORMAL_ATTACK_MISS, $miss->event);
        $this->assertSame(2, $miss->delta);

        $beforeEvade = $actor->getResource('aim');
        $resources->beginAction($actor, $state);
        $evade = $resources->recordNormalAttackResolution($actor, $target, $state, HitResult::EVADE);
        $resources->finishAction($actor, $state);
        $this->assertFalse($evade->applied);
        $this->assertSame($beforeEvade, $actor->getResource('aim'));
    }

    public function test_aim_rank_five_and_nine_add_action_local_accuracy_before_the_existing_clamp(): void
    {
        $actor = $this->actor(65);
        $target = $this->actor(null, false);

        foreach ([
            [5, 98, HitResult::HIT],
            [5, 99, HitResult::MISS],
            [9, 98, HitResult::HIT],
            [9, 99, HitResult::MISS],
        ] as [$rank, $roll, $expected]) {
            $skill = $this->art(65, $rank);
            $actor->jobArtOrigins[(int) $skill->id] = 'current';
            $random = $this->random([$roll]);
            $this->assertSame(
                $expected,
                $this->resolver($random)->resolveJobArt($actor, $target, $skill, 'pve'),
                "rank={$rank},roll={$roll}",
            );
            $this->assertSame(1, $random->calls);
        }

        $rankFive = $this->art(65, 5);
        $actor->jobArtOrigins[(int) $rankFive->id] = 'inherited';
        $this->assertSame(HitResult::HIT, $this->resolver($this->random([91]))->resolveJobArt($actor, $target, $rankFive, 'pve'));

        $actor->jobArtOrigins[(int) $rankFive->id] = 'current';
        config(['battle.job_art_v2.resources' => false]);
        $this->assertSame(HitResult::MISS, $this->resolver($this->random([91]))->resolveJobArt($actor, $target, $rankFive, 'pve'));
    }

    public function test_sp_pressure_uses_actual_current_sp_without_a_battle_wide_cap(): void
    {
        [$actor, $target, $state] = $this->battle(65, targetMp: 1_000, targetMaxMp: 1_000);
        $pressure = app(JobArtV2SpPressureService::class);
        $rankFive = $this->art(65, 5);
        $rankNine = $this->art(65, 9);
        $actor->jobArtOrigins[(int) $rankFive->id] = 'current';
        $actor->jobArtOrigins[(int) $rankNine->id] = 'current';

        $this->begin($actor, $state);
        $first = $pressure->applyOnHit($actor, $target, $state, $rankFive, HitResult::HIT);
        $duplicate = $pressure->applyOnHit($actor, $target, $state, $rankFive, HitResult::HIT);
        $this->assertSame([30, 1_000, 970, 30], [
            $first->requested,
            $first->spBefore,
            $first->spAfter,
            $first->actualLoss,
        ]);
        $this->assertSame('duplicate_sp_pressure_event', $duplicate->blockedReason);

        foreach (range(1, 2) as $_) {
            $this->begin($actor, $state);
            $pressure->applyOnHit($actor, $target, $state, $rankNine, HitResult::HIT);
        }
        $this->begin($actor, $state);
        $third = $pressure->applyOnHit($actor, $target, $state, $rankNine, HitResult::HIT);

        $this->assertSame(820, $target->mp);
        $this->assertSame(50, $third->actualLoss);
        $this->assertSame(1_000, $target->maxMp);
        $this->assertSame(1_000, $actor->mp);

        $this->begin($actor, $state);
        $continued = $pressure->applyOnHit($actor, $target, $state, $rankNine, HitResult::HIT);
        $this->assertTrue($continued->applied);
        $this->assertSame(50, $continued->actualLoss);
        $this->assertSame(770, $target->mp);
        $this->assertArrayNotHasKey('battle_cap', $continued->toArray());
        $this->assertArrayNotHasKey('remaining_cap', $continued->toArray());
    }

    public function test_sp_pressure_requires_a_trusted_hit_and_also_applies_when_the_card_is_inherited(): void
    {
        [$actor, $target, $state] = $this->battle(65, targetMp: 20, targetMaxMp: 1_000);
        $pressure = app(JobArtV2SpPressureService::class);
        $rankNine = $this->art(65, 9);
        $actor->jobArtOrigins[(int) $rankNine->id] = 'current';

        $this->begin($actor, $state);
        $low = $pressure->applyOnHit($actor, $target, $state, $rankNine, HitResult::HIT);
        $this->assertSame(50, $low->requested);
        $this->assertSame(20, $low->actualLoss);
        $this->assertSame(0, $target->mp);

        foreach ([HitResult::MISS, HitResult::EVADE] as $result) {
            $target->mp = 1_000;
            $this->begin($actor, $state);
            $this->assertFalse($pressure->applyOnHit($actor, $target, $state, $rankNine, $result)->applied);
            $this->assertSame(1_000, $target->mp);
        }

        $actor->jobArtOrigins[(int) $rankNine->id] = 'inherited';
        $this->begin($actor, $state);
        $this->assertTrue($pressure->applyOnHit($actor, $target, $state, $rankNine, HitResult::HIT)->applied);
        $this->assertSame(950, $target->mp);
    }

    public function test_command_rank_one_produces_four_points_and_other_actions_keep_the_passive_gain(): void
    {
        [$actor, $target, $state] = $this->battle(69);
        $resources = app(JobArtV2ResourceService::class);

        $resources->beginAction($actor, $state);
        $rankOne = $resources->applyJobArtCast($actor, $state, $this->art(69, 1));
        $resources->finishAction($actor, $state);
        $this->assertTrue($rankOne->applied);
        $this->assertSame(4, $rankOne->delta);
        $this->assertSame(4, $actor->getResource('command_points'));
        $this->assertSame(BattleActionType::JOB_ART, $state->battleActionResults()[0]->actionType);

        $resources->beginAction($actor, $state);
        $hit = $resources->recordNormalAttackResolution($actor, $target, $state, HitResult::HIT);
        $duplicateHit = $resources->recordNormalAttackResolution($actor, $target, $state, HitResult::HIT);
        $resources->finishAction($actor, $state);
        $resources->finishAction($actor, $state);
        $this->assertSame(4, $hit->delta);
        $this->assertFalse($duplicateHit->applied);
        $this->assertSame(9, $actor->getResource('command_points'));
        $this->assertSame(BattleActionType::NORMAL_ATTACK, $state->battleActionResults()[1]->actionType);

        $resources->beginAction($actor, $state);
        $resources->recordNormalAttackResolution($actor, $target, $state, HitResult::MISS);
        $resources->finishAction($actor, $state);
        $this->assertSame(10, $actor->getResource('command_points'));

        $resources->beginAction($actor, $state);
        $resources->markCurrentJobSkillAction($actor, $state, $this->currentSkill());
        $resources->finishAction($actor, $state);
        $this->assertSame(11, $actor->getResource('command_points'));

        $resources->beginAction($actor, $state);
        $resources->finishAction($actor, $state);
        $this->assertSame(11, $actor->getResource('command_points'));
        $this->assertSame(BattleActionType::NO_ACTION, $state->battleActionResults()[4]->actionType);
    }

    public function test_command_and_observation_apply_the_field_bonus_once_to_the_same_action(): void
    {
        [$actor, $target, $state] = $this->battle(69);
        $state->replacePrimaryField(new FieldState('observation', 'player', 3, 1, 1, 1));
        $state->replaceFieldOverlay(null);
        $resources = app(JobArtV2ResourceService::class);

        $resources->beginAction($actor, $state);
        $hit = $resources->recordNormalAttackResolution($actor, $target, $state, HitResult::HIT);
        $resources->finishAction($actor, $state);

        $this->assertSame(5, $hit->delta);
        $this->assertSame(6, $actor->getResource('command_points'));
    }

    public function test_battle_action_result_is_immutable_once_per_source_action(): void
    {
        [$actor, $target, $state] = $this->battle(69);
        $sourceActionId = $state->beginSourceAction();
        $normal = new BattleActionResult($sourceActionId, $state->actorKey($actor), $state->actorKey($target), BattleActionType::NORMAL_ATTACK);
        $jobArt = new BattleActionResult($sourceActionId, $state->actorKey($actor), $state->actorKey($target), BattleActionType::JOB_ART);

        $this->assertTrue($state->recordBattleActionResult($normal));
        $this->assertFalse($state->recordBattleActionResult($jobArt));
        $this->assertSame(BattleActionType::NORMAL_ATTACK, $state->battleActionResult($sourceActionId)?->actionType);
        $this->assertCount(1, $state->battleActionResults());
    }

    private function begin(BattleActor $actor, BattleState $state): void
    {
        app(JobArtV2ResourceService::class)->beginAction($actor, $state);
    }

    private function resolver(JobArtV2HitRandomSource $random): ActionResolver
    {
        $catalog = new JobArtV2PrototypeCatalog;

        return new ActionResolver(
            new JobArtV2FeatureGate($catalog),
            new DamageCalculator,
            $random,
            new JobArtV2ActiveEvasionProvider,
            prototypeCatalog: $catalog,
        );
    }

    private function random(array $rolls): JobArtV2HitRandomSource
    {
        return new class($rolls) extends JobArtV2HitRandomSource
        {
            public int $calls = 0;

            public function __construct(private readonly array $rolls) {}

            public function percentRoll(): int
            {
                return $this->rolls[$this->calls++] ?? 100;
            }
        };
    }

    /** @return array{BattleActor, BattleActor, BattleState} */
    private function battle(int $jobId, int $targetMp = 1_000, int $targetMaxMp = 1_000): array
    {
        $actor = $this->actor($jobId);
        $target = $this->actor(null, false, $targetMp, $targetMaxMp);

        return [$actor, $target, new BattleState($actor, $target)];
    }

    private function actor(?int $jobId, bool $isPlayer = true, int $mp = 1_000, int $maxMp = 1_000): BattleActor
    {
        $actor = new BattleActor($isPlayer ? 'player' : 'enemy', $isPlayer, [
            'hp' => 10_000,
            'max_hp' => 10_000,
            'mp' => $mp,
            'max_mp' => $maxMp,
            'agi' => 100,
            'current_job_id' => $jobId,
        ]);
        if (in_array($jobId, [65, 69], true)) {
            $actor->jobArts = [$this->art((int) $jobId, 1), $this->art((int) $jobId, 5), $this->art((int) $jobId, 9)];
            foreach ($actor->jobArts as $art) {
                $actor->jobArtOrigins[(int) $art->id] = 'current';
                $actor->jobArtRates[(int) $art->id] = 1.0;
            }
        }

        return $actor;
    }

    private function art(int $jobId, int $rank): Skill
    {
        $skill = new Skill([
            'name' => match ([$jobId, $rank]) {
                [65, 1] => '鋼冠起動',
                [65, 5] => '鋼冠機砲',
                [65, 9] => '鋼冠グラビトンコア',
                [69, 1] => '戦冠指揮',
                [69, 5] => '戦冠総攻令',
                [69, 9] => '王戦アークフォーメーション',
                default => "job-{$jobId}-rank-{$rank}",
            },
            'skill_type' => 'job_art',
            'job_id' => $jobId,
            'learn_rank' => $rank,
            'effect_template' => $jobId === 65 ? 'MAGICAL_DAMAGE' : 'PHYSICAL_DAMAGE',
            'hit_count' => 1,
        ]);
        $skill->setAttribute('id', ($jobId * 100) + $rank);

        return $skill;
    }

    private function currentSkill(): Skill
    {
        $skill = new Skill([
            'name' => '現在職技',
            'skill_type' => 'active',
            'effect_template' => 'PHYSICAL_DAMAGE',
        ]);
        $skill->setAttribute('id', 69_000);

        return $skill;
    }
}
