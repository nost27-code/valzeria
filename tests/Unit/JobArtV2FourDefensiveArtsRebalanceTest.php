<?php

namespace Tests\Unit;

use App\Http\Controllers\JobArtController;
use App\Models\Character;
use App\Models\CharacterJobArtSlot;
use App\Models\Skill;
use App\Services\Battle\BattleActionType;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageApplicationRequest;
use App\Services\Battle\DamageApplicationService;
use App\Services\Battle\DamageCalculator;
use App\Services\Battle\DamageSourceType;
use App\Services\Battle\DirectAttackResolution;
use App\Services\Battle\HitResult;
use App\Services\FieldState;
use App\Services\JobArtBattleSupportService;
use App\Services\JobArtV2BattleRules;
use App\Services\JobArtV2CardDescriptionCatalog;
use App\Services\JobArtV2CrownBalanceCatalog;
use App\Services\JobArtV2DefenseService;
use App\Services\JobArtV2FeatureGate;
use App\Services\JobArtV2FieldService;
use App\Services\JobArtV2GuardState;
use App\Services\JobArtV2LoadoutPresenter;
use App\Services\JobArtV2ParryRandomSource;
use App\Services\JobArtV2ProgressionService;
use App\Services\JobArtV2PrototypeCatalog;
use App\Services\JobArtV2ResourceService;
use App\Services\JobArtV2RoleEffectCatalog;
use App\Services\JobArtV2RoleEffectService;
use App\Services\JobArtV2SpCostCalculator;
use App\Services\JobArtV2SpPressureService;
use App\Services\JobArtV2UltimateCounterplayCatalog;
use App\Services\JobArtV2UltimateCounterplayService;
use App\Services\JobArtV2UltimateGuardState;
use App\Services\JobArtV2UltimatePreparationState;
use Mockery;
use Tests\TestCase;

final class JobArtV2FourDefensiveArtsRebalanceTest extends TestCase
{
    private int $nextSkillId = 910_000;

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
            'battle.job_art_v2.c_design_prototype' => true,
            'battle.job_art_v2.ultimate_counterplay' => true,
        ]);
    }

    public function test_catalogs_freeze_the_four_approved_effect_contracts(): void
    {
        $roles = app(JobArtV2RoleEffectCatalog::class);
        $balances = app(JobArtV2CrownBalanceCatalog::class);
        $counterplay = app(JobArtV2UltimateCounterplayCatalog::class);
        $prototype = app(JobArtV2PrototypeCatalog::class);

        $fortress = $this->art(15, 9, '不落要塞', 'GUARD_BARRIER', 255);
        $caravan = $this->art(20, 9, '大商隊の守護', 'GUARD_BARRIER', 255);
        $diamond = $this->art(21, 9, '金剛不壊', 'GUARD_BARRIER', 89);

        $this->assertSame(30, $roles->forArt($fortress)['next_action_damage_reduction_percent']);
        $this->assertSame(
            JobArtV2UltimateCounterplayCatalog::FORTRESS_GUARD,
            $counterplay->effectFor($fortress),
        );

        $this->assertSame(3, $roles->forArt($caravan)['guard']['charges']);
        $this->assertSame(0.20, $roles->forArt($caravan)['guard']['damage_reduction_rate']);
        $this->assertSame(0.03, $roles->forArt($caravan)['guard']['on_trigger']['hp_recovery_rate']);
        $this->assertArrayNotHasKey('sp_recovery_rate', $roles->forArt($caravan)['guard']['on_trigger']);
        $this->assertSame(20, $balances->reductionPercent($caravan));

        $this->assertSame(0.25, $roles->forArt($diamond)['guard']['damage_reduction_rate']);
        $this->assertSame(165, $roles->forArt($diamond)['guard']['on_trigger']['counter']['power']);
        $this->assertSame(['mage', 'undead'], $roles->forArt($diamond)['guard']['on_trigger']['counter']['species_keys']);
        $this->assertSame(25, $balances->reductionPercent($diamond));

    }

    public function test_three_defensive_ultimates_keep_thirteen_sp_and_twelve_resource_on_all_paths_and_origins(): void
    {
        config(['battle.job_art_v2.sp_power_scaling.enabled' => true]);
        $definitions = [
            [15, '不落要塞', 'holy_guard'],
            [20, '大商隊の守護', 'catalyst'],
            [21, '金剛不壊', 'break'],
        ];
        $support = app(JobArtBattleSupportService::class);
        $calculator = app(JobArtV2SpCostCalculator::class);
        foreach (['pve', 'boss', 'tower', 'pvp', 'champ', 'arena_npc'] as $battleType) {
            foreach ($definitions as [$jobId, $name, $resourceKey]) {
                foreach (['current', 'inherited'] as $origin) {
                    foreach ([true, false] as $isPlayer) {
                        $actor = $this->actor('guarded', $isPlayer, $origin === 'current' ? $jobId : 62);
                        $actor->spPowerReference = 1_000_000;
                        $other = $this->actor('other', ! $isPlayer, 48);
                        $state = new BattleState($isPlayer ? $actor : $other, $isPlayer ? $other : $actor, $battleType);
                        $art = $this->art($jobId, 9, $name, 'GUARD_BARRIER', 255);
                        $actor->jobArts = [$art];
                        $actor->jobArtOrigins[(int) $art->id] = $origin;
                        $actor->configureResource($resourceKey, 12);
                        $actor->setResource($resourceKey, 11);
                        $resource = app(JobArtV2ResourceService::class);
                        $this->assertSame(JobArtV2ResourceService::BLOCKED_BY_RESOURCE, $resource->eligibilityBlockReason($actor, $art, $state));
                        $actor->setResource($resourceKey, 12);
                        $this->assertNull($resource->eligibilityBlockReason($actor, $art, $state));
                        foreach ([100, 1_000, 1_000_000] as $maxSp) {
                            $this->assertSame(13, $calculator->forCurrentJob($art, $maxSp, $actor->currentJobId, $origin));
                        }
                        $this->beginAction($actor, $state);
                        $this->assertSame(13, $support->spCost($actor, $art));
                        $this->assertTrue($support->consumeAndMarkUse($actor, $state, $art));
                        $this->assertSame(487, $actor->mp);
                        $this->assertSame(0, $actor->getResource($resourceKey));
                    }
                }
            }
        }
    }

    public function test_caravan_description_and_guard_do_not_restore_sp_even_with_large_maximum_sp(): void
    {
        $art = $this->art(20, 9, '大商隊の守護', 'GUARD_BARRIER', 255);
        $description = app(JobArtV2CardDescriptionCatalog::class)->defaultDescription($art);
        $this->assertStringContainsString('最大HPの3%', $description);
        $this->assertStringNotContainsString('最大SP', $description);
        $actor = $this->actor('merchant', true, 20, ['hp' => 500, 'mp' => 40, 'max_mp' => 1_000_000]);
        $enemy = $this->actor('enemy', false, 48);
        $state = new BattleState($actor, $enemy, 'pve');
        $this->castRoleArt($actor, $enemy, $state, $art);
        $application = new DamageApplicationService(app(JobArtV2DefenseService::class));
        for ($i = 0; $i < 3; ++$i) {
            $this->beginAction($enemy, $state);
            $this->applyDirect($application, $state, $enemy, $actor, 100);
            $this->assertSame(40, $actor->mp);
        }
        $this->assertSame(350, $actor->hp);
        $this->assertNull($actor->jobArtV2GuardState());
        $this->assertStringNotContainsString('SPが', implode("\n", $state->pullDeferredDamageLogs()));
    }

    public function test_real_slot_card_displays_updated_effects_and_uses_thirteen_sp_display_metadata(): void
    {
        $decorate = new \ReflectionMethod(JobArtController::class, 'decorateArtsForDisplay');
        foreach ([[15, '不落要塞', '50%'], [20, '大商隊の守護', '最大HPの3%'], [21, '金剛不壊', '165%']] as [$jobId, $name, $effect]) {
            $art = $this->art($jobId, 9, $name, 'GUARD_BARRIER', 255, ['art_cost' => 3]);
            $art->setAttribute('job_art_origin', 'current');
            $art->setRelation('jobClass', null);
            $decorate->invoke(new JobArtController(), collect([$art]), new Character(['current_job_id' => $jobId]), 1_000,
                app(JobArtV2SpCostCalculator::class), app(JobArtV2BattleRules::class), app(JobArtV2LoadoutPresenter::class));
            $slot = new CharacterJobArtSlot(['skill_id' => $art->id, 'slot_no' => 1, 'battle_context' => 'normal', 'activation_policy' => 'normal']);
            $html = view('job-arts.partials.slot-card', [
                'jobArtV2UiEnabled' => true, 'slotContext' => 'normal', 'slotNo' => 1, 'slot' => $slot,
                'contextArts' => collect([$art]), 'allAvailableArts' => collect([$art]), 'maxSp' => 1_000,
                'activationPolicyLabels' => ['normal' => '通常'], 'activationPolicyDescriptions' => ['normal' => '通常'],
                'contextTotalCost' => 3, 'maxCost' => 9,
            ])->render();
            $visible = html_entity_decode(strip_tags($html));
            $this->assertSame(13, $art->getAttribute('job_art_display_sp_cost'));
            $this->assertStringContainsString('Cost 3', $visible);
            $this->assertStringContainsString($effect, $visible);
            if ($jobId === 20) {
                $this->assertStringNotContainsString('最大SP', $visible);
            }
        }
    }

    public function test_caravan_guard_uses_three_source_actions_and_one_recovery_per_multihit_action_on_all_six_paths(): void
    {
        foreach (['pve', 'boss', 'tower', 'pvp', 'champ', 'arena_npc'] as $battleType) {
            $guarded = $this->actor('merchant', true, 20, [
                'hp' => 500,
                'max_hp' => 1_000,
                'mp' => 40,
                'max_mp' => 100,
            ]);
            $attacker = $this->actor('attacker', false, 48);
            $state = new BattleState($guarded, $attacker, $battleType);
            $art = $this->art(20, 9, '大商隊の守護', 'GUARD_BARRIER', 255);
            $guarded->replaceJobArtV2GuardState(new JobArtV2GuardState(0.40));
            $this->castRoleArt($guarded, $attacker, $state, $art);

            $this->assertSame(3, $guarded->jobArtV2GuardState()?->charges, $battleType);
            $this->assertSame(0.20, $guarded->jobArtV2GuardState()?->rate, $battleType);
            $application = new DamageApplicationService(app(JobArtV2DefenseService::class));

            $this->beginAction($attacker, $state);
            $first = $this->applyDirect($application, $state, $attacker, $guarded, 100, 1, 2);
            $second = $this->applyDirect($application, $state, $attacker, $guarded, 100, 2, 2);
            $this->assertSame([80, 80], [$first->requestedDamage, $second->requestedDamage], $battleType);
            $this->assertSame(370, $guarded->hp, $battleType);
            $this->assertSame(40, $guarded->mp, $battleType);
            $this->assertSame(2, $guarded->jobArtV2GuardState()?->charges, $battleType);

            $this->beginAction($attacker, $state);
            $this->applyDirect($application, $state, $attacker, $guarded, 100);
            $this->assertSame([320, 40, 1], [
                $guarded->hp,
                $guarded->mp,
                $guarded->jobArtV2GuardState()?->charges,
            ], $battleType);

            $this->beginAction($attacker, $state);
            $this->applyDirect($application, $state, $attacker, $guarded, 100);
            $this->assertSame([270, 40], [$guarded->hp, $guarded->mp], $battleType);
            $this->assertNull($guarded->jobArtV2GuardState(), $battleType);
        }
    }

    public function test_diamond_guard_counters_once_with_attack_vs_spirit_and_the_magic_type_bonus(): void
    {
        $calculator = Mockery::mock(DamageCalculator::class);
        $calculator->shouldReceive('calculateMagicalDamage')
            ->once()
            ->withArgs(function (
                BattleActor $counter,
                BattleActor $target,
                int $power,
                bool $critical,
                ?int $overrideAtk,
                ?int $overrideDef,
            ): bool {
                return $power === 165
                    && ! $critical
                    && $overrideAtk === $counter->effectiveStr()
                    && $overrideDef === $target->effectiveSpr();
            })
            ->andReturn(100);
        $defense = new JobArtV2DefenseService(
            featureGate: app(JobArtV2FeatureGate::class),
            prototypeCatalog: app(JobArtV2PrototypeCatalog::class),
            resourceService: app(JobArtV2ResourceService::class),
            randomSource: app(JobArtV2ParryRandomSource::class),
            damageCalculator: $calculator,
        );
        $application = new DamageApplicationService($defense);
        $guarded = $this->actor('martial artist', true, 21, [
            'hp' => 1_000,
            'max_hp' => 1_000,
            'str' => 140,
        ]);
        $attacker = $this->actor('mage', false, 48, [
            'hp' => 1_000,
            'max_hp' => 1_000,
            'spr' => 90,
            'normal_attack_type' => 'magical',
        ]);
        $state = new BattleState($guarded, $attacker, 'pve');
        $art = $this->art(21, 9, '金剛不壊', 'GUARD_BARRIER', 89);
        $this->castRoleArt($guarded, $attacker, $state, $art, $defense);

        $this->beginAction($attacker, $state);
        $incoming = $this->applyDirect($application, $state, $attacker, $guarded, 100, 1, 2);

        $this->assertSame(75, $incoming->requestedDamage);
        $this->assertSame(925, $guarded->hp);
        $this->assertSame(880, $attacker->hp, 'The counter is immediate and receives the magical normal-attack x1.20 bonus.');
        $this->applyDirect($application, $state, $attacker, $guarded, 100, 2, 2);
        $this->assertSame(850, $guarded->hp);
        $this->assertSame(880, $attacker->hp, 'A multihit source action can trigger the counter only once.');
        $this->assertNull($guarded->jobArtV2GuardState());
        $this->assertStringContainsString('金剛不壊で反撃', implode("\n", $state->pullDeferredDamageLogs()));
    }

    public function test_champ_aggregated_multihit_completes_the_guard_reaction_once(): void
    {
        $guarded = $this->actor('merchant', true, 20, [
            'hp' => 500,
            'max_hp' => 1_000,
            'mp' => 40,
            'max_mp' => 100,
        ]);
        $attacker = $this->actor('champ', false, 48);
        $state = new BattleState($guarded, $attacker, 'champ');
        $art = $this->art(20, 9, '大商隊の守護', 'GUARD_BARRIER', 255);
        $this->castRoleArt($guarded, $attacker, $state, $art);

        $this->beginAction($attacker, $state);
        $result = $this->applyDirect(
            new DamageApplicationService(app(JobArtV2DefenseService::class)),
            $state,
            $attacker,
            $guarded,
            300,
            3,
            3,
        );

        $this->assertSame(240, $result->requestedDamage);
        $this->assertSame(290, $guarded->hp);
        $this->assertSame(40, $guarded->mp);
        $this->assertSame(2, $guarded->jobArtV2GuardState()?->charges);
    }

    public function test_fortress_uses_thirty_percent_normally_and_fifty_percent_against_the_announced_source_action(): void
    {
        $guardian = $this->actor('guardian', true, 15);
        $enemy = $this->actor('boss', false, 48);
        $art = $this->art(15, 9, '不落要塞', 'GUARD_BARRIER', 255);

        $ordinary = new BattleState($guardian, $enemy, 'pve');
        $this->castRoleArt($guardian, $enemy, $ordinary, $art);
        $this->assertSame(30, $guardian->damageReductionRate);

        $guardian = $this->actor('guardian', true, 15);
        $enemy = $this->actor('boss', false, 48);
        $state = new BattleState($guardian, $enemy, 'boss');
        $state->pendingEnemyActionId = 101;
        $state->pendingEnemyActionTurns = 1;
        $state->enemyTelegraphContext = [
            'cycle_id' => 1,
            'action_id' => 101,
            'action_name' => '予告大技',
            'can_be_guarded' => true,
            'delayed' => false,
            'executing' => false,
        ];
        $counterplay = app(JobArtV2UltimateCounterplayService::class);
        $role = app(JobArtV2RoleEffectService::class);
        $guardian->jobArts = [$art];
        $guardian->jobArtOrigins[(int) $art->id] = 'current';
        $this->beginAction($guardian, $state, $role);
        $counterplay->beginJobArtCast($guardian, $state, $art);
        $role->completeJobArtCast($guardian, $enemy, $state, $art, null);

        $this->assertSame(0, $guardian->damageReductionRate);
        $this->assertSame(0.50, $guardian->jobArtV2UltimateCounterplayState()->ultimateGuard?->rate);
        $this->assertTrue((bool) $state->enemyTelegraphContext['fortress_secondary_suppressed']);

        $counterplay->markPveTelegraphExecuting($state, true);
        $this->beginAction($enemy, $state, $role);
        $resolution = new DirectAttackResolution(
            $state->currentSourceActionId(),
            $enemy,
            $guardian,
            HitResult::HIT,
            'magical',
            true,
            BattleActionType::JOB_ART,
        );
        $this->assertSame(50, app(JobArtV2DefenseService::class)->resolveDamage($state, $resolution, 100));
        $this->assertTrue($state->harmfulAttachedEffectsBlockedFor($guardian));
    }

    public function test_fortress_block_gate_stops_attached_sp_debuff_and_lineage_interference(): void
    {
        $attacker = $this->actor('attacker', true, 19);
        $target = $this->actor('target', false, 15, ['mp' => 500, 'max_mp' => 500]);
        $state = new BattleState($attacker, $target, 'pve');
        $sourceActionId = $state->beginSourceAction();
        $state->beginJobArtV2RoleAction($sourceActionId, [
            'actor_key' => $state->actorKey($attacker),
            'source_action_id' => $sourceActionId,
            'fortress_harmful_effects_blocked_target_key' => $state->actorKey($target),
        ]);
        $spArt = $this->art(19, 9, '魂喰らい', 'DRAIN', 255, [
            'damage_type' => 'magical',
            'sp_pressure_rate' => 0.10,
        ]);
        $attacker->jobArts = [$spArt];
        $attacker->jobArtOrigins[(int) $spArt->id] = 'current';
        $result = app(JobArtV2SpPressureService::class)
            ->applyOnHit($attacker, $target, $state, $spArt, HitResult::HIT);
        $this->assertFalse($result->applied);
        $this->assertSame(500, $target->mp);

        $debuffArt = $this->art(19, 5, 'スピリットスティール', 'DRAIN', 165, [
            'damage_type' => 'magical',
            'enemy_spr_down_percent' => 12,
            'duration_turns' => 3,
        ]);
        $attacker->jobArts[] = $debuffArt;
        $attacker->jobArtOrigins[(int) $debuffArt->id] = 'current';
        $timed = app(JobArtV2RoleEffectService::class)
            ->applyTimedStructuredDebuffs($attacker, $target, $state, $debuffArt);
        $this->assertSame([], $timed['changes']);
        $this->assertSame([], $target->jobArtV2TimedEffects());

        $alchemist = $this->actor('alchemist', true, 67);
        $resourceTarget = $this->actor('resource target', false, 15);
        $resourceState = new BattleState($alchemist, $resourceTarget, 'pve');
        $sourceActionId = $resourceState->beginSourceAction();
        $resourceState->beginJobArtV2RoleAction($sourceActionId, [
            'actor_key' => $resourceState->actorKey($alchemist),
            'source_action_id' => $sourceActionId,
            'fortress_harmful_effects_blocked_target_key' => $resourceState->actorKey($resourceTarget),
        ]);
        $goldArt = $this->art(67, 9, '金冠ミダスフィールド', 'MAGICAL_DAMAGE', 315, [
            'damage_type' => 'magical',
        ]);
        $targetArt = $this->art(15, 1, '不屈の誓い', 'GUARD_BARRIER', 0);
        $alchemist->jobArts = [$goldArt];
        $alchemist->jobArtOrigins[(int) $goldArt->id] = 'current';
        $resourceTarget->jobArts = [$targetArt];
        $resourceTarget->jobArtOrigins[(int) $targetArt->id] = 'current';
        $progression = app(JobArtV2ProgressionService::class);
        $progression->beginJobArtCast($alchemist, $resourceState, $goldArt);
        $progression->completeJobArtCast($alchemist, $resourceTarget, $resourceState, $goldArt, HitResult::HIT);

        $this->assertSame([], $resourceTarget->jobArtV2ProgressionState()->resourceSuppressions);
    }

    public function test_fortress_marks_the_announced_competitive_action_before_champ_resolves_aggregated_damage(): void
    {
        $attacker = $this->actor('alchemist', true, 67);
        $target = $this->actor('guardian', false, 15);
        $ultimate = $this->art(67, 9, '金冠ミダスフィールド', 'MAGICAL_DAMAGE', 315, [
            'damage_type' => 'magical',
        ]);
        $fortress = $this->art(15, 9, '不落要塞', 'GUARD_BARRIER', 255);
        $attacker->jobArts = [$ultimate];
        $attacker->jobArtOrigins[(int) $ultimate->id] = 'current';
        $target->jobArts = [$fortress];
        $target->jobArtOrigins[(int) $fortress->id] = 'current';
        $state = new BattleState($attacker, $target, 'champ');
        $preparation = new JobArtV2UltimatePreparationState(
            cycleId: 7,
            mainLineage: 'gold_crown',
            resourceKey: 'gold_corrosion',
            preparedSourceActionId: 1,
            phase: JobArtV2UltimatePreparationState::PHASE_READY,
        );
        $attacker->jobArtV2UltimateCounterplayState()->preparation = $preparation;
        $target->jobArtV2UltimateCounterplayState()->ultimateGuard = new JobArtV2UltimateGuardState(
            targetActorKey: $state->actorKey($attacker),
            targetCycleId: 7,
            rate: 0.50,
            effect: JobArtV2UltimateCounterplayCatalog::FORTRESS_GUARD,
            responseSkillId: 1509,
        );

        $this->beginAction($attacker, $state);
        app(JobArtV2UltimateCounterplayService::class)->beginJobArtCast($attacker, $state, $ultimate);

        $this->assertTrue($state->harmfulAttachedEffectsBlockedFor($target));
    }



    private function castRoleArt(
        BattleActor $actor,
        BattleActor $target,
        BattleState $state,
        Skill $art,
        ?JobArtV2DefenseService $defense = null,
    ): void {
        $actor->jobArts = [$art];
        $actor->jobArtOrigins[(int) $art->id] = 'current';
        $role = app(JobArtV2RoleEffectService::class);
        $this->beginAction($actor, $state, $role);
        $execution = clone $art;
        $role->applyForExecution($actor, $target, $state, $art, $execution);
        $role->beginJobArtCast($actor, $state, $art);

        if ($defense === null) {
            $role->completeJobArtCast($actor, $target, $state, $art, null);

            return;
        }

        $metadata = app(JobArtV2RoleEffectCatalog::class)->forArt($art)['guard'];
        $defense->applyGuard(
            $actor,
            $state,
            app(JobArtV2CrownBalanceCatalog::class)->reductionPercent($art) / 100,
            charges: $metadata['charges'],
            sourceSkillId: (int) $art->id,
            onTrigger: $metadata['on_trigger'],
        );
    }

    private function beginAction(
        BattleActor $actor,
        BattleState $state,
        ?JobArtV2RoleEffectService $role = null,
    ): int {
        $sourceActionId = $state->beginSourceAction();
        if ($role !== null) {
            $role->beginAction($actor, $state, $sourceActionId);
        } else {
            $state->beginJobArtV2RoleAction($sourceActionId, [
                'actor_key' => $state->actorKey($actor),
                'source_action_id' => $sourceActionId,
            ]);
        }

        return $sourceActionId;
    }

    private function applyDirect(
        DamageApplicationService $application,
        BattleState $state,
        BattleActor $attacker,
        BattleActor $target,
        int $damage,
        int $hitIndex = 1,
        int $hitCount = 1,
    ) {
        $resolution = new DirectAttackResolution(
            $state->currentSourceActionId(),
            $attacker,
            $target,
            HitResult::HIT,
            'magical',
            true,
            BattleActionType::NORMAL_ATTACK,
        );

        return $application->apply(new DamageApplicationRequest(
            sourceActor: $attacker,
            targetActor: $target,
            resolvedDamage: $damage,
            sourceType: DamageSourceType::NORMAL_ATTACK,
            sourceId: null,
            battleType: $state->battleType,
            hitResult: HitResult::HIT,
            hitIndex: $hitIndex,
            hitCount: $hitCount,
            battleState: $state,
            directAttackResolution: $resolution,
        ));
    }

    /** @param array<string, int|float|string|bool> $overrides */
    private function actor(string $name, bool $isPlayer, int $jobId, array $overrides = []): BattleActor
    {
        return new BattleActor($name, $isPlayer, array_replace([
            'hp' => 1_000,
            'max_hp' => 1_000,
            'mp' => 500,
            'max_mp' => 500,
            'str' => 100,
            'def' => 100,
            'agi' => 100,
            'mag' => 100,
            'spr' => 100,
            'luk' => 100,
            'current_job_id' => $jobId,
        ], $overrides));
    }

    /** @param array<string, int|float|string|bool> $attributes */
    private function art(
        int $jobId,
        int $rank,
        string $name,
        string $template,
        int $power,
        array $attributes = [],
    ): Skill {
        $skill = new Skill(array_replace([
            'job_id' => $jobId,
            'learn_rank' => $rank,
            'name' => $name,
            'skill_type' => 'job_art',
            'effect_template' => $template,
            'damage_type' => $template === 'MAGICAL_DAMAGE' ? 'magical' : 'support',
            'power' => $power,
            'power_multiplier' => $power / 100,
            'hit_count' => $template === 'GUARD_BARRIER' ? 0 : 1,
            'duration_turns' => 1,
            'reward_scope' => 'none',
        ], $attributes));
        $skill->setAttribute('id', ++$this->nextSkillId);

        return $skill;
    }
}
