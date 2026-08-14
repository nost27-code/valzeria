<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageSourceType;
use App\Services\FieldOverlayState;
use App\Services\FieldSnapshot;
use App\Services\FieldState;
use App\Services\JobArtV2FieldModifierResolver;
use App\Services\JobArtV2FieldService;
use App\Services\JobArtV2RandomSource;
use App\Services\JobArtV2ResourceService;
use App\Services\JobArtV2SelectionService;
use Tests\TestCase;

class JobArtV2FieldModifierResolverTest extends TestCase
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

    public function test_all_five_trusted_field_definitions_apply_only_to_the_declared_target_axis_and_scope(): void
    {
        [$owner, $opponent, $state] = $this->battle();
        $service = $this->service();

        $this->injectPrimary($state, 'star_light', 'player');
        $this->beginMagicalAction($owner, $state);
        $this->assertSame(110, $service->modifyDamage($owner, $state, 100, DamageSourceType::JOB_ART));
        $this->assertSame(100, $service->modifyDamage($owner, $state, 100, DamageSourceType::PURE));
        $state->setFieldActionContext($owner, $state->currentFieldSnapshot(), 'job_art', 'physical');
        $this->assertSame(100, $service->modifyDamage($owner, $state, 100, DamageSourceType::JOB_ART));
        $this->beginMagicalAction($opponent, $state);
        $this->assertSame(100, $service->modifyDamage($opponent, $state, 100, DamageSourceType::JOB_ART));

        $this->injectOverlay($state, 'melody', 'player');
        $this->beginMagicalAction($owner, $state);
        $this->assertSame(53, $service->activationRate($owner, $state, 50));
        $this->assertSame(50, $service->activationRate($opponent, $state, 50));
        $this->assertSame(0.0, app(JobArtV2FieldModifierResolver::class)->resolve(
            $state->currentFieldSnapshot(),
            'player',
            'activation_rate_delta',
            'normal_attack',
        ));

        $this->injectPrimary($state, 'sanctuary', 'player');
        $service->beginAction($owner, $state, 3);
        $this->assertSame(110, $service->modifyHpHeal($owner, $state, 100));
        $this->assertSame(100, $service->modifyHpHeal($opponent, $state, 100));
        $battleService = file_get_contents(base_path('app/Services/BattleService.php'));
        $this->assertStringNotContainsString('modifyFieldHpHeal($attacker, $state, $recover)', $battleService);
        $this->assertStringNotContainsString('modifyFieldHpHeal($attacker, $state, $reduction)', $battleService);

        $this->injectPrimary($state, 'silence', 'enemy');
        $service->beginAction($owner, $state, 4);
        $this->assertSame(3, $service->modifyResourceGain($owner, $state, 4));
        $this->assertSame(0, $service->modifyResourceGain($owner, $state, 1));

        $this->injectPrimary($state, 'observation', 'player');
        $service->beginAction($owner, $state, 5);
        $this->assertSame(5.0, $service->accuracyDelta($owner, $state));
        $this->assertSame(0.0, $service->accuracyDelta($opponent, $state));
        $this->assertSame(5, $service->modifyResourceGain($owner, $state, 4));
        $this->assertSame(2, $service->modifyResourceGain($owner, $state, 1));
    }

    public function test_same_axis_uses_strongest_per_direction_then_clamps_to_the_field_limit(): void
    {
        $resolver = app(JobArtV2FieldModifierResolver::class);

        $this->assertSame(4.0, $resolver->resolveValues([1, 4, 3], 'activation_rate_delta'));
        $this->assertSame(-3.0, $resolver->resolveValues([-1, -3, -2], 'activation_rate_delta'));
        $this->assertSame(2.0, $resolver->resolveValues([5, 4, -3, -1], 'activation_rate_delta'));
        $this->assertSame(5.0, $resolver->resolveValues([9], 'activation_rate_delta'));
        $this->assertSame(-8.0, $resolver->resolveValues([-99], 'accuracy_delta'));
        $this->assertSame(1.0, $resolver->resolveValues([4], 'resource_gain_delta'));
        $this->assertSame(0.15, $resolver->resolveValues([0.90], 'damage_multiplier'));
        $this->assertSame(-0.15, $resolver->resolveValues([-0.90], 'heal_multiplier'));
    }

    public function test_melody_adds_three_points_to_the_single_job_art_roll_only(): void
    {
        [$owner, , $state] = $this->battle();
        $skill = $this->art(53, 1);
        $skill->activation_rate = 50;
        $skill->sp_cost_fixed = 1;
        $owner->jobArts = [$skill];
        $owner->jobArtActivationPolicy = 'aggressive';
        $this->injectOverlay($state, 'melody', 'player');
        app(JobArtV2ResourceService::class)->beginAction($owner, $state);
        $random = new class extends JobArtV2RandomSource
        {
            public int $calls = 0;

            public function percentRoll(): int
            {
                $this->calls++;

                return 52;
            }
        };
        $selection = new JobArtV2SelectionService(
            $random,
            app(\App\Services\JobArtV2FinisherConditionProvider::class),
            app(\App\Services\JobArtV2SpCostCalculator::class),
            app(\App\Services\JobArtV2BattleRules::class),
            app(JobArtV2ResourceService::class),
            $this->service(),
        );

        $result = $selection->selectForTurn($owner, $state);

        $this->assertSame(53, $result->activationRate);
        $this->assertSame($skill->id, $result->skill?->id);
        $this->assertSame(1, $random->calls);
        $this->assertFalse($result->retriedAfterMiss);
    }

    public function test_resource_field_delta_is_applied_once_after_base_gain_is_combined(): void
    {
        [$owner, , $state] = $this->battle();
        $this->injectPrimary($state, 'observation', 'player');
        $resources = app(JobArtV2ResourceService::class);
        $resources->beginAction($owner, $state);

        $producer = $this->art(53, 1);
        $result = $resources->applyJobArtCast($owner, $state, $producer);

        $this->assertSame(5, $result->delta);
        $this->assertSame(5, $owner->getResource('star_mark'));
        $this->assertFalse($resources->applyJobArtCast($owner, $state, $producer)->applied);
        $this->assertSame(5, $owner->getResource('star_mark'));

        [$normalOwner, , $normalState] = $this->battle();
        $this->injectPrimary($normalState, 'observation', 'player');
        $resources->beginAction($normalOwner, $normalState);
        $this->assertSame(2, $resources->recordNormalAttackHit($normalOwner, $normalState)->delta);

        [$silenced, , $silenceState] = $this->battle();
        $this->injectPrimary($silenceState, 'silence', 'enemy');
        $resources->beginAction($silenced, $silenceState);
        $this->assertSame(3, $resources->applyJobArtCast($silenced, $silenceState, $producer)->delta);

        [$silencedNormal, , $silenceNormalState] = $this->battle();
        $this->injectPrimary($silenceNormalState, 'silence', 'enemy');
        $resources->beginAction($silencedNormal, $silenceNormalState);
        $this->assertSame(0, $resources->recordNormalAttackHit($silencedNormal, $silenceNormalState)->delta);
        $this->assertSame(0, $silencedNormal->getResource('star_mark'));
    }

    public function test_new_field_does_not_self_apply_but_an_existing_refreshed_field_uses_the_action_snapshot(): void
    {
        [$owner, , $state] = $this->battle();
        $service = $this->service();
        $state->turnCount = 1;

        $service->beginAction($owner, $state, 1);
        $service->markSkillAction($owner, $state, $this->art(53, 1));
        $service->deployPrimary($owner, $state, 'star_light', 531, 1);
        $this->assertSame(100, $service->modifyDamage($owner, $state, 100, DamageSourceType::JOB_ART));
        $this->assertSame(4, $service->modifyResourceGain($owner, $state, 4));

        $state->turnCount = 2;
        $service->beginAction($owner, $state, 2);
        $service->markSkillAction($owner, $state, $this->art(53, 1));
        $service->deployPrimary($owner, $state, 'star_light', 531, 2);
        $this->assertSame(110, $service->modifyDamage($owner, $state, 100, DamageSourceType::JOB_ART));
    }

    public function test_resolver_is_pure_and_does_not_consume_rng_or_mutate_snapshot(): void
    {
        $snapshot = new FieldSnapshot(
            new FieldState('observation', 'player', 3, 1, 1, 1),
            new FieldOverlayState('melody', 'player', 1, 2, 2, 1),
        );
        $before = serialize($snapshot);
        mt_srand(9910);
        $expected = mt_rand();

        mt_srand(9910);
        $resolver = app(JobArtV2FieldModifierResolver::class);
        $this->assertSame(5.0, $resolver->resolve($snapshot, 'player', 'accuracy_delta'));
        $this->assertSame(3.0, $resolver->resolve($snapshot, 'player', 'activation_rate_delta', 'job_art'));

        $this->assertSame($before, serialize($snapshot));
        $this->assertSame($expected, mt_rand());
    }

    private function beginMagicalAction(BattleActor $actor, BattleState $state): void
    {
        $this->service()->beginAction($actor, $state, 1);
        $state->setFieldActionContext($actor, $state->currentFieldSnapshot(), 'job_art', 'magical');
    }

    private function injectPrimary(BattleState $state, string $key, string $ownerKey): void
    {
        $state->replacePrimaryField(new FieldState($key, $ownerKey, 3, 1, 1, 1));
        $state->replaceFieldOverlay(null);
    }

    private function injectOverlay(BattleState $state, string $key, string $ownerKey): void
    {
        $state->replaceFieldOverlay(new FieldOverlayState($key, $ownerKey, 1, 2, 2, 1));
    }

    private function service(): JobArtV2FieldService
    {
        return app(JobArtV2FieldService::class);
    }

    /** @return array{BattleActor, BattleActor, BattleState} */
    private function battle(): array
    {
        $owner = new BattleActor('owner', true, [
            'hp' => 100,
            'max_hp' => 100,
            'mp' => 100,
            'max_mp' => 100,
            'str' => 100,
            'mag' => 100,
            'current_job_id' => 53,
        ]);
        $starter = $this->art(53, 1);
        $owner->jobArts = [$starter];
        $owner->jobArtOrigins[(int) $starter->id] = 'current';
        $owner->jobArtRates[(int) $starter->id] = 1.0;
        $opponent = new BattleActor('opponent', false, [
            'hp' => 100,
            'max_hp' => 100,
            'mp' => 100,
            'max_mp' => 100,
            'current_job_id' => 24,
        ]);

        return [$owner, $opponent, new BattleState($owner, $opponent)];
    }

    private function art(int $jobId, int $rank): Skill
    {
        $skill = new Skill([
            'name' => $jobId === 53 && $rank === 1 ? '星読の瞬き' : "job-{$jobId}-rank-{$rank}",
            'skill_type' => 'job_art',
            'job_id' => $jobId,
            'learn_rank' => $rank,
            'effect_template' => 'MAGICAL_DAMAGE',
        ]);
        $skill->setAttribute('id', ($jobId * 10) + $rank);

        return $skill;
    }
}
