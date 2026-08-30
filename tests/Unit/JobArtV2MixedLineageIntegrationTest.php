<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\BattleActionType;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageCalculator;
use App\Services\Battle\DamageSourceType;
use App\Services\Battle\DirectAttackResolution;
use App\Services\Battle\HitResult;
use App\Services\JobArtBattleSupportService;
use App\Services\JobArtV2BreakDebuffService;
use App\Services\JobArtV2BreakDebuffState;
use App\Services\JobArtV2CounterStanceState;
use App\Services\JobArtV2DamageSemanticsResolver;
use App\Services\JobArtV2DefenseService;
use App\Services\JobArtV2FeatureGate;
use App\Services\JobArtV2FieldService;
use App\Services\JobArtV2FinisherConditionProvider;
use App\Services\JobArtV2GuardState;
use App\Services\JobArtV2ParryRandomSource;
use App\Services\JobArtV2PenetrationService;
use App\Services\JobArtV2PrototypeCatalog;
use App\Services\JobArtV2RandomSource;
use App\Services\JobArtV2ResourceService;
use App\Services\JobArtV2BattleRules;
use App\Services\JobArtV2SelectionService;
use App\Services\JobArtV2SpCostCalculator;
use App\Services\JobArtV2SpPressureService;
use Tests\TestCase;

class JobArtV2MixedLineageIntegrationTest extends TestCase
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
            'battle.job_art_v2.penetration' => true,
            'battle.job_art_v2.penetration_stance' => true,
        ]);
    }

    public function test_cross_lineage_resource_is_active_independently_and_visible_to_the_hud(): void
    {
        [$actor, , $state] = $this->battle(68, 24);
        $inheritedProducer = $this->art(65, 1, 'PHYSICAL_DAMAGE');
        $inheritedFinisher = $this->art(65, 9, 'PHYSICAL_DAMAGE');
        $this->attachInherited($actor, $inheritedProducer);
        $this->attachInherited($actor, $inheritedFinisher);
        $actor->jobArts = [$inheritedProducer, $inheritedFinisher];
        $actor->configureResource('break', 12);
        $actor->setResource('break', 7);

        $resources = app(JobArtV2ResourceService::class);
        $resources->beginAction($actor, $state);
        $this->assertSame(4, $resources->applyJobArtCast($actor, $state, $inheritedProducer)->delta);
        $resources->finishAction($actor, $state);

        $this->assertSame(7, $actor->getResource('break'));
        $this->assertSame(4, $actor->getResource('aim'));
        $this->assertFalse($resources->isFinisherReady($actor, $inheritedFinisher));

        $hud = app(JobArtBattleSupportService::class)->battleHud($state);
        $this->assertSame('aim', $hud['actors'][0]['resource']['key']);
        $this->assertSame(['aim'], array_column($hud['actors'][0]['resources'], 'key'));
        $jobArtAction = collect($hud['actions'])->firstWhere('action_kind', 'job_art');
        $this->assertNotNull($jobArtAction);
        $this->assertSame('job-65-rank-1', $jobArtAction['action_name']);
        $this->assertSame(4, collect($hud['actors'][0]['resources'])->firstWhere('key', 'aim')['points']);
    }

    public function test_break_target_state_changes_inherited_physical_and_magical_damage(): void
    {
        [$actor, $target] = $this->battle(68, 66, targetDef: 800, targetSpr: 800);
        $physical = $this->art(65, 5, 'PHYSICAL_DAMAGE', 285);
        $magical = $this->art(53, 5, 'MAGICAL_DAMAGE', 285);
        $this->attachInherited($actor, $physical);
        $this->attachInherited($actor, $magical);

        $support = app(JobArtBattleSupportService::class);
        $physicalExecution = $support->skillForExecution($actor, $physical);
        $magicalExecution = $support->skillForExecution($actor, $magical);
        $this->assertSame('physical', $physicalExecution->damage_type);
        $this->assertSame('magical', $magicalExecution->damage_type);

        $calculator = app(DamageCalculator::class);
        srand(25_001);
        $physicalBefore = $calculator->calculatePhysicalDamage($actor, $target, 285);
        srand(25_002);
        $magicalBefore = $calculator->calculateMagicalDamage($actor, $target, 285);

        $target->replaceBreakDebuffState(new JobArtV2BreakDebuffState(0.15, 3, 1));
        srand(25_001);
        $physicalAfter = $calculator->calculatePhysicalDamage($actor, $target, 285);
        srand(25_002);
        $magicalAfter = $calculator->calculateMagicalDamage($actor, $target, 285);

        $this->assertGreaterThan($physicalBefore, $physicalAfter);
        $this->assertGreaterThan($magicalBefore, $magicalAfter);
    }

    public function test_star_light_applies_to_inherited_magical_damage_without_porting_the_source_field_effect(): void
    {
        [$actor, , $state] = $this->battle(53, 24);
        $inheritedMagic = $this->art(6, 5, 'MAGICAL_DAMAGE', 250);
        $inheritedFieldLock = $this->art(85, 5, 'TIME_CONTROL_CURRENT_ONLY');
        $this->attachInherited($actor, $inheritedMagic);
        $this->attachInherited($actor, $inheritedFieldLock);

        $resources = app(JobArtV2ResourceService::class);
        $fields = app(JobArtV2FieldService::class);
        $resources->beginAction($actor, $state);
        $sourceActionId = $state->currentSourceActionId();
        $this->assertNotNull($sourceActionId);
        $fields->deployPrimary($actor, $state, 'star_light', 53_01, $sourceActionId);
        $resources->finishAction($actor, $state);

        $resources->beginAction($actor, $state);
        $fields->markSkillAction($actor, $state, $inheritedMagic);
        $this->assertSame(1_100, $fields->modifyDamage($actor, $state, 1_000, DamageSourceType::JOB_ART));
        $this->assertFalse($fields->isFieldOnlyArt($actor, $state, $inheritedFieldLock));
        $this->assertFalse($fields->applyJobArtCast($actor, $state, $inheritedFieldLock)->applied);
        $this->assertSame('star_light', $state->primaryField()?->key);
    }

    public function test_crown_transmute_suppression_does_not_port_to_an_unrelated_inherited_art(): void
    {
        [$actor, $target, $state] = $this->battle(67, 68, hp: 1_000, maxHp: 1_000, mp: 500, maxMp: 1_000);
        $currentProducer = $this->art(67, 1, 'MAGICAL_DAMAGE_REWARD', 225);
        $currentProducer->name = '金冠錬符';
        $inheritedFinisher = $this->art(65, 9, 'PHYSICAL_DAMAGE', 570);
        $this->attachCurrent($actor, $currentProducer);
        $this->attachInherited($actor, $inheritedFinisher);
        $actor->jobArts = [$currentProducer, $inheritedFinisher];
        $targetProducer = $this->art(68, 1, 'PHYSICAL_DAMAGE', 225);
        $this->attachCurrent($target, $targetProducer);
        $target->jobArts = [$targetProducer];
        $actor->configureResource('catalyst', 12);
        $actor->setResource('catalyst', 4);

        $resources = app(JobArtV2ResourceService::class);
        $support = app(JobArtBattleSupportService::class);
        $this->assertNotNull($support->beginAction($actor, $state));
        $resources->applyJobArtCast($actor, $state, $currentProducer);
        $support->consumeAndMarkUse($actor, $state, $currentProducer);
        $support->completeJobArtCast($actor, $state, $currentProducer, HitResult::HIT, $target);

        $this->assertSame(8, $actor->getResource('catalyst'));
        $this->assertCount(1, $target->jobArtV2ProgressionState()->resourceSuppressions);
        $suppressionBefore = $target->jobArtV2ProgressionState()->resourceSuppressions;

        $resources->finishAction($actor, $state);
        $resources->beginAction($actor, $state);
        $before = [$actor->hp, $actor->mp, $actor->getResource('catalyst'), $target->getResource('break')];
        $resources->applyJobArtCast($actor, $state, $inheritedFinisher);
        $this->assertSame($before, [$actor->hp, $actor->mp, $actor->getResource('catalyst'), $target->getResource('break')]);
        $this->assertSame($suppressionBefore, $target->jobArtV2ProgressionState()->resourceSuppressions);
    }

    public function test_guard_and_parry_states_remain_effective_with_offensive_inherited_loadouts(): void
    {
        $defense = new JobArtV2DefenseService(
            app(JobArtV2FeatureGate::class),
            app(JobArtV2PrototypeCatalog::class),
            app(JobArtV2ResourceService::class),
            new class extends JobArtV2ParryRandomSource
            {
                public function percentRoll(): int
                {
                    return 1;
                }
            },
        );

        [$guardian, $attacker, $guardState] = $this->battle(66, 65);
        $guardian->jobArts = [$this->art(65, 5, 'PHYSICAL_DAMAGE')];
        $this->attachInherited($guardian, $guardian->jobArts[0]);
        $guardian->replaceJobArtV2GuardState(new JobArtV2GuardState(0.20));
        app(JobArtV2ResourceService::class)->beginAction($attacker, $guardState);
        $guardResolution = $this->directResolution($guardState, $attacker, $guardian);
        $this->assertSame(80, $defense->resolveDamage($guardState, $guardResolution, 100));

        [$counter, $counterAttacker, $counterState] = $this->battle(60, 65);
        $counterArt = $this->art(60, 1, 'PHYSICAL_DAMAGE');
        $aimArt = $this->art(65, 5, 'PHYSICAL_DAMAGE');
        $counter->jobArts = [$counterArt, $aimArt];
        $this->attachCurrent($counter, $counterArt);
        $this->attachInherited($counter, $aimArt);
        $counter->replaceCounterStanceState(new JobArtV2CounterStanceState(2, 1, 0.20));
        app(JobArtV2ResourceService::class)->beginAction($counterAttacker, $counterState);
        $parryResolution = $this->directResolution($counterState, $counterAttacker, $counter);
        $this->assertSame(0, $defense->resolveDamage($counterState, $parryResolution, 100));
        $this->assertSame(1, $counter->getResource('sword_momentum'));
    }

    public function test_inherited_arts_receive_their_full_written_v2_effects(): void
    {
        [$actor, $target, $state] = $this->battle(68, 66);

        $pierce = $this->art(62, 5, 'PHYSICAL_DAMAGE', 285);
        $aim = $this->art(65, 5, 'PHYSICAL_DAMAGE', 285);
        $eclipse = $this->art(61, 5, 'PHYSICAL_DAMAGE', 285);
        foreach ([$pierce, $aim, $eclipse] as $skill) {
            $this->attachInherited($actor, $skill);
        }
        $actor->jobArts = [$pierce, $aim, $eclipse];

        $this->assertNotNull(app(JobArtV2PenetrationService::class)->trustedRateFor($actor, $pierce));
        $this->assertNotNull(app(JobArtV2DamageSemanticsResolver::class)->forExecution($actor, $eclipse));

        app(JobArtV2ResourceService::class)->beginAction($actor, $state);
        $spBefore = $target->mp;
        $this->assertTrue(app(JobArtV2SpPressureService::class)->applyOnHit(
            $actor,
            $target,
            $state,
            $aim,
            HitResult::HIT,
        )->applied);
        $this->assertLessThan($spBefore, $target->mp);
    }

    public function test_mixed_selection_uses_the_same_rules_in_all_six_battle_contexts(): void
    {
        $random = new class extends JobArtV2RandomSource
        {
            public function percentRoll(): int
            {
                return 1;
            }
        };
        $selection = new JobArtV2SelectionService(
            $random,
            app(JobArtV2FinisherConditionProvider::class),
            app(JobArtV2SpCostCalculator::class),
            app(JobArtV2BattleRules::class),
            app(JobArtV2ResourceService::class),
            app(JobArtV2FieldService::class),
        );

        foreach (['pve', 'boss', 'tower', 'pvp', 'champ', 'arena_npc'] as $battleType) {
            [$actor, $target] = $this->battle(68, 65);
            $state = new BattleState($actor, $target, $battleType);
            $inherited = $this->art(65, 5, 'PHYSICAL_DAMAGE', 285);
            $current = $this->art(68, 1, 'DAMAGE_BUFF', 225);
            $actor->jobArts = [$inherited, $current];
            $this->attachInherited($actor, $inherited);
            $this->attachCurrent($actor, $current);
            $actor->jobArtPolicies[(int) $inherited->id] = 'aggressive';
            $actor->jobArtPolicies[(int) $current->id] = 'aggressive';
            $actor->configureResource('aim', 12);
            $actor->setResource('aim', 4);

            $result = $selection->selectForTurn($actor, $state);

            $this->assertSame($inherited, $result->skill, $battleType);
            $this->assertFalse($result->rankNinePrioritized, $battleType);
            app(JobArtV2ResourceService::class)->beginAction($actor, $state);
            $this->assertSame(-4, app(JobArtV2ResourceService::class)->applyJobArtCast($actor, $state, $inherited)->delta, $battleType);
            $this->assertSame(0, $actor->getResource('aim'), $battleType);
            $this->assertSame(0, $actor->getResource('break'), $battleType);
        }
    }

    private function directResolution(BattleState $state, BattleActor $attacker, BattleActor $target): DirectAttackResolution
    {
        return new DirectAttackResolution(
            sourceActionId: (int) $state->currentSourceActionId(),
            attacker: $attacker,
            target: $target,
            hitResult: HitResult::HIT,
            damageCategory: 'physical',
            direct: true,
            actionType: BattleActionType::JOB_ART,
        );
    }

    /** @return array{BattleActor, BattleActor, BattleState} */
    private function battle(
        int $playerJob,
        int $enemyJob,
        int $hp = 10_000,
        int $maxHp = 10_000,
        int $mp = 1_000,
        int $maxMp = 1_000,
        int $targetDef = 500,
        int $targetSpr = 500,
    ): array {
        $player = $this->actor('player', true, $playerJob, $hp, $maxHp, $mp, $maxMp);
        $enemy = $this->actor('enemy', false, $enemyJob, 10_000, 10_000, 1_000, 1_000, $targetDef, $targetSpr);

        return [$player, $enemy, new BattleState($player, $enemy)];
    }

    private function actor(
        string $name,
        bool $isPlayer,
        int $jobId,
        int $hp = 10_000,
        int $maxHp = 10_000,
        int $mp = 1_000,
        int $maxMp = 1_000,
        int $def = 500,
        int $spr = 500,
    ): BattleActor {
        return new BattleActor($name, $isPlayer, [
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

    private function art(int $jobId, int $rank, string $template, int $power = 225): Skill
    {
        $skill = new Skill([
            'name' => "job-{$jobId}-rank-{$rank}",
            'skill_type' => 'job_art',
            'job_id' => $jobId,
            'learn_rank' => $rank,
            'effect_template' => $template,
            'damage_type' => str_starts_with($template, 'MAGICAL') ? 'magical' : 'physical',
            'power' => $power,
            'power_multiplier' => $power / 100,
            'activation_rate' => 100,
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

    private function attachInherited(BattleActor $actor, Skill $skill): void
    {
        $actor->jobArtOrigins[(int) $skill->id] = 'inherited';
        $actor->jobArtRates[(int) $skill->id] = 1.0;
    }
}
