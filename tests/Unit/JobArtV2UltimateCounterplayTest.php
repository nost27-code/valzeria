<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\BattleActionType;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DirectAttackResolution;
use App\Services\Battle\HitResult;
use App\Services\JobArtV2BattleRules;
use App\Services\JobArtBattleSupportService;
use App\Services\JobArtV2BattleHudService;
use App\Services\JobArtV2DefenseService;
use App\Services\JobArtV2FinisherConditionProvider;
use App\Services\JobArtV2GuardState;
use App\Services\JobArtV2ProgressionService;
use App\Services\JobArtV2RandomSource;
use App\Services\JobArtV2SelectionService;
use App\Services\JobArtV2LoadoutPresenter;
use App\Services\JobArtV2PreparedEffectState;
use App\Services\JobArtV2SpCostCalculator;
use App\Services\JobArtV2UltimateCounterplayCatalog;
use App\Services\JobArtV2UltimateCounterplayService;
use App\Services\JobArtV2UltimatePreparationState;
use Tests\TestCase;

final class JobArtV2UltimateCounterplayTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $originalFlags = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'battle.job_art_v2.loadout_v2',
            'battle.job_art_v2.dynamic_single',
            'battle.job_art_v2.normalized_sp',
            'battle.job_art_v2.hit_resolution',
            'battle.job_art_v2.damage_application',
            'battle.job_art_v2.resources',
            'battle.job_art_v2.c_design_prototype',
            'battle.job_art_v2.ultimate_counterplay',
        ] as $key) {
            $this->originalFlags[$key] = config($key);
        }

        config([
            'battle.job_art_v2.loadout_v2' => true,
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.normalized_sp' => true,
            'battle.job_art_v2.hit_resolution' => true,
            'battle.job_art_v2.damage_application' => true,
            'battle.job_art_v2.resources' => true,
            'battle.job_art_v2.c_design_prototype' => true,
            'battle.job_art_v2.ultimate_counterplay' => true,
        ]);
    }

    protected function tearDown(): void
    {
        config($this->originalFlags);
        parent::tearDown();
    }

    public function test_gate_is_default_safe_and_limited_to_rank_pvp_and_two_sided_champ(): void
    {
        [$player, $enemy] = $this->actors();
        $gate = app(\App\Services\JobArtV2FeatureGate::class);

        $this->assertTrue($gate->usesUltimateCounterplay(new BattleState($player, $enemy, 'pvp')));
        $this->assertTrue($gate->usesUltimateCounterplay(new BattleState($player, $enemy, 'champ')));
        $this->assertFalse($gate->usesUltimateCounterplay(new BattleState($player, $enemy, 'pve')));
        $this->assertTrue($gate->usesUltimateCounterplay(new BattleState($player, $enemy, 'arena_npc')));

        $enemy->jobArts = [];
        $this->assertFalse($gate->usesUltimateCounterplay(new BattleState($player, $enemy, 'champ')));

        config(['battle.job_art_v2.ultimate_counterplay' => false]);
        $enemy->jobArts = $this->huntArts();
        $this->assertFalse($gate->usesUltimateCounterplay(new BattleState($player, $enemy, 'pvp')));
    }

    public function test_resource_twelve_enters_preparation_without_rank_five_and_becomes_ready_after_one_response(): void
    {
        [$owner, $responder] = $this->actors();
        $owner->jobArts = [$this->commandArt(1), $this->commandArt(9)];
        $this->setOrigins($owner);
        $state = new BattleState($owner, $responder, 'pvp');
        $service = app(JobArtV2UltimateCounterplayService::class);
        $owner->configureResource('command_points', 12);
        $owner->setResource('command_points', 12);

        $this->action($owner, $state, fn () => null, $service);
        $this->assertTrue($owner->jobArtV2UltimateCounterplayState()->preparation?->isPreparing());
        $this->assertSame(
            JobArtV2UltimateCounterplayService::BLOCKED_PREPARING,
            $service->eligibilityBlockReason($owner, $state, $this->commandArt(9)),
        );

        $this->action($responder, $state, fn () => null, $service);
        $this->assertTrue($owner->jobArtV2UltimateCounterplayState()->preparation?->isReady());
        $this->assertNull($service->eligibilityBlockReason($owner, $state, $this->commandArt(9)));
    }

    public function test_rank_five_is_not_a_preparation_prerequisite_in_any_competitive_route(): void
    {
        foreach (['pvp', 'champ', 'arena_npc'] as $battleType) {
            [$owner, $responder] = $this->actors();
            $owner->jobArts = [$this->commandArt(1), $this->commandArt(9)];
            $this->setOrigins($owner);
            $state = new BattleState($owner, $responder, $battleType);
            $owner->configureResource('command_points', 12);
            $owner->setResource('command_points', 12);

            $this->action($owner, $state, fn () => null, app(JobArtV2UltimateCounterplayService::class));

            $this->assertTrue(
                $owner->jobArtV2UltimateCounterplayState()->preparation?->isPreparing(),
                $battleType,
            );
        }
    }

    public function test_hunt_hit_cancels_preparation_preserves_twelve_and_replaces_normal_seal(): void
    {
        [$owner, $hunter] = $this->actors();
        $state = new BattleState($owner, $hunter, 'pvp');
        $service = app(JobArtV2UltimateCounterplayService::class);
        $progression = app(JobArtV2ProgressionService::class);
        $this->forcePreparing($owner, $state, 'command', 'command_points');
        $owner->setResource('command_points', 12);
        $ownerKey = 'actor:'.spl_object_id($hunter);
        $owner->jobArtV2ProgressionState()->huntingMarks[$ownerKey] = 1;
        $owner->jobArtV2ProgressionState()->lastActionCategory = 'attack';

        $this->beginAction($hunter, $state);
        $skill = $this->huntArt(5);
        $service->beginJobArtCast($hunter, $state, $skill);
        $progression->beginJobArtCast($hunter, $state, $skill);
        $service->completeJobArtCast($hunter, $owner, $state, $skill, HitResult::HIT);
        $progression->completeJobArtCast($hunter, $owner, $state, $skill, HitResult::HIT);
        $service->finishAction($hunter, $state);

        $this->assertNull($owner->jobArtV2UltimateCounterplayState()->preparation);
        $this->assertSame(12, $owner->getResource('command_points'));
        $this->assertFalse($owner->jobArtV2UltimateCounterplayState()->mainRankFiveEstablished);
        $this->assertTrue($owner->jobArtV2UltimateCounterplayState()->huntCancelResistance);
        $this->assertSame([], $owner->jobArtV2ProgressionState()->sealReservations);
        $this->assertTrue((bool) $state->jobArtV2RoleAction()['ultimate_counterplay_hunt_cancelled']);

        $this->action($owner, $state, fn () => null, $service);
        $this->assertFalse($owner->jobArtV2UltimateCounterplayState()->huntCancelResistance);
    }

    public function test_hunt_miss_does_not_cancel_the_preparation_or_reserve_the_normal_seal(): void
    {
        [$owner, $hunter] = $this->actors();
        $state = new BattleState($owner, $hunter, 'pvp');
        $service = app(JobArtV2UltimateCounterplayService::class);
        $progression = app(JobArtV2ProgressionService::class);
        $this->forcePreparing($owner, $state, 'command', 'command_points');
        $owner->setResource('command_points', 12);
        $owner->jobArtV2ProgressionState()->huntingMarks['actor:'.spl_object_id($hunter)] = 1;

        $this->beginAction($hunter, $state);
        $skill = $this->huntArt(5);
        $service->beginJobArtCast($hunter, $state, $skill);
        $progression->beginJobArtCast($hunter, $state, $skill);
        $service->completeJobArtCast($hunter, $owner, $state, $skill, HitResult::MISS);
        $progression->completeJobArtCast($hunter, $owner, $state, $skill, HitResult::MISS);
        $service->finishAction($hunter, $state);

        $this->assertTrue($owner->jobArtV2UltimateCounterplayState()->preparation?->isReady());
        $this->assertTrue($owner->jobArtV2UltimateCounterplayState()->mainRankFiveEstablished);
        $this->assertSame([], $owner->jobArtV2ProgressionState()->sealReservations);
    }

    public function test_guard_replaces_sixteen_percent_and_reduces_all_hits_of_only_the_targeted_ultimate(): void
    {
        [$owner, $guard] = $this->actors();
        $guard->currentJobId = 66;
        $guard->jobArts = $this->guardArts();
        $this->setOrigins($guard);
        $state = new BattleState($owner, $guard, 'pvp');
        $service = app(JobArtV2UltimateCounterplayService::class);
        $defense = app(JobArtV2DefenseService::class);
        $this->forcePreparing($owner, $state, 'command', 'command_points');
        $owner->setResource('command_points', 12);

        $this->beginAction($guard, $state);
        $guard->replaceJobArtV2GuardState(new JobArtV2GuardState(0.16));
        $service->beginJobArtCast($guard, $state, $this->guardArt(5));
        $this->assertNull($guard->jobArtV2GuardState());
        $this->assertSame(0.35, $guard->jobArtV2UltimateCounterplayState()->ultimateGuard?->rate);
        $service->finishAction($guard, $state);

        $this->beginAction($owner, $state);
        $service->beginJobArtCast($owner, $state, $this->commandArt(9));
        $sourceActionId = $state->currentSourceActionId();
        $resolution = new DirectAttackResolution(
            $sourceActionId,
            $owner,
            $guard,
            HitResult::HIT,
            'physical',
            true,
            BattleActionType::JOB_ART,
        );

        $this->assertSame(65, $defense->resolveDamage($state, $resolution, 100));
        $this->assertSame(32, $defense->resolveDamage($state, $resolution, 50));
        $this->assertNull($guard->jobArtV2UltimateCounterplayState()->ultimateGuard);
        $this->assertSame(0.35, $state->damageTrace($guard, $sourceActionId)?->guardRate);
        $service->finishAction($owner, $state);
    }

    public function test_command_delays_readiness_for_exactly_one_owner_action_and_only_once_per_cycle(): void
    {
        [$owner] = $this->actors();
        $commander = $this->actor('commander', 48, $this->commandArts());
        $state = new BattleState($owner, $commander, 'pvp');
        $service = app(JobArtV2UltimateCounterplayService::class);
        $this->forcePreparing($owner, $state, 'command', 'command_points');
        $owner->setResource('command_points', 12);

        $this->beginAction($commander, $state);
        $service->beginJobArtCast($commander, $state, $this->commandArt(5));
        $service->beginJobArtCast($commander, $state, $this->commandArt(5));
        $service->finishAction($commander, $state);

        $preparation = $owner->jobArtV2UltimateCounterplayState()->preparation;
        $this->assertTrue($preparation?->isReady());
        $this->assertSame(1, $preparation?->delayOwnActionsRemaining);
        $this->assertSame(
            JobArtV2UltimateCounterplayService::BLOCKED_DELAYED,
            $service->eligibilityBlockReason($owner, $state, $this->commandArt(9)),
        );

        $this->action($owner, $state, fn () => null, $service);
        $this->assertSame(0, $preparation->delayOwnActionsRemaining);
        $this->assertNull($service->eligibilityBlockReason($owner, $state, $this->commandArt(9)));
    }

    public function test_ready_ultimate_and_response_art_share_priority_and_use_slot_order(): void
    {
        [$actor, $target] = $this->actors();
        $actor->currentJobId = 66;
        $guardFive = $this->guardArt(5);
        $guardNine = $this->guardArt(9);
        $guardOne = $this->guardArt(1);
        $actor->jobArts = [$guardFive, $guardNine, $guardOne];
        $this->setOrigins($actor);
        $state = new BattleState($actor, $target, 'pvp');
        $this->forceReady($actor, 'guard', 'holy_guard');
        $this->forcePreparing($target, $state, 'command', 'command_points');
        $actor->configureResource('holy_guard', 12);
        $actor->setResource('holy_guard', 12);

        $service = $this->selection([1, 1]);
        $this->assertSame((int) $guardFive->id, $service->selectForTurn($actor, $state)->candidateSkillId);

        $actor->jobArts = [$guardNine, $guardFive, $guardOne];
        $this->setOrigins($actor);
        $this->assertSame((int) $guardNine->id, $service->selectForTurn($actor, $state)->candidateSkillId);
    }

    public function test_activation_failure_does_not_restart_a_ready_preparation(): void
    {
        [$owner, $target] = $this->actors();
        $state = new BattleState($owner, $target, 'pvp');
        $preparation = $this->forceReady($owner, 'command', 'command_points');
        $owner->configureResource('command_points', 12);
        $owner->setResource('command_points', 12);
        $owner->jobArts = [$this->commandArt(9)];
        $this->setOrigins($owner);

        $result = $this->selection([100])->selectForTurn($owner, $state);

        $this->assertFalse($result->activated);
        $this->assertSame($preparation, $owner->jobArtV2UltimateCounterplayState()->preparation);
        $this->assertTrue($preparation->isReady());
    }

    public function test_cross_lineage_rank_five_is_not_required_for_the_equipped_ultimate(): void
    {
        $guardArts = $this->guardArts();
        $actor = $this->actor('mixed', 62, [
            $this->pierceArt(1),
            $this->pierceArt(5),
            $this->pierceArt(9),
            $guardArts[0],
            $guardArts[1],
        ]);
        $target = $this->actor('target', 48, $this->commandArts());
        $state = new BattleState($actor, $target, 'pvp');
        $service = app(JobArtV2UltimateCounterplayService::class);
        $actor->configureResource('dragon_force', 12);
        $actor->setResource('dragon_force', 12);

        $this->beginAction($actor, $state);
        $service->beginJobArtCast($actor, $state, $guardArts[1]);
        $service->finishAction($actor, $state);

        $this->assertFalse($actor->jobArtV2UltimateCounterplayState()->mainRankFiveEstablished);
        $this->assertTrue($actor->jobArtV2UltimateCounterplayState()->preparation?->isPreparing());
        $this->assertSame(
            JobArtV2UltimateCounterplayService::BLOCKED_PREPARING,
            $service->eligibilityBlockReason($actor, $state, $this->pierceArt(9)),
        );
    }

    public function test_pve_keeps_the_immediate_main_ultimate_without_prerequisite_or_warning(): void
    {
        [$owner, $target] = $this->actors();
        $state = new BattleState($owner, $target, 'pve');
        $owner->configureResource('command_points', 12);
        $owner->setResource('command_points', 12);

        $result = $this->selection([1])->selectForTurn($owner, $state);

        $this->assertSame((int) $this->commandArt(9)->id, $result->candidateSkillId);
        $this->assertTrue($result->activated);
        $this->assertNull($owner->existingJobArtV2UltimateCounterplayState());
    }

    public function test_pve_telegraph_hunt_response_delays_the_enemy_action_once(): void
    {
        $hunter = $this->actor('hunter', 54, $this->huntArts());
        $enemy = $this->enemyActor('boss', 48);
        $state = $this->pveTelegraph($hunter, $enemy);
        $service = app(JobArtV2UltimateCounterplayService::class);
        $hunterKey = 'actor:'.spl_object_id($hunter);
        $enemy->jobArtV2ProgressionState()->huntingMarks[$hunterKey] = 1;
        $rankFive = $this->huntArt(5);

        $this->assertTrue($service->isResponseCandidate($hunter, $state, $rankFive));
        $this->beginAction($hunter, $state);
        $service->beginJobArtCast($hunter, $state, $rankFive);
        $service->completeJobArtCast($hunter, $enemy, $state, $rankFive, HitResult::HIT);
        $service->completeJobArtCast($hunter, $enemy, $state, $rankFive, HitResult::HIT);

        $this->assertSame(2, $state->pendingEnemyActionTurns);
        $this->assertTrue((bool) $state->enemyTelegraphContext['delayed']);
    }

    public function test_pve_telegraph_guard_uses_the_announced_cycle_for_all_hits(): void
    {
        $guardian = $this->actor('guardian', 66, $this->guardArts());
        $enemy = $this->enemyActor('boss', 48);
        $state = $this->pveTelegraph($guardian, $enemy, canBeGuarded: true);
        $service = app(JobArtV2UltimateCounterplayService::class);
        $rankFive = $this->guardArt(5);

        $this->beginAction($guardian, $state);
        $service->beginJobArtCast($guardian, $state, $rankFive);
        $service->markPveTelegraphExecuting($state, true);
        $this->beginAction($enemy, $state);
        $resolution = new DirectAttackResolution(
            $state->currentSourceActionId(), $enemy, $guardian, HitResult::HIT,
            'physical', true, BattleActionType::JOB_ART,
        );
        $defense = app(JobArtV2DefenseService::class);

        $this->assertSame(65, $defense->resolveDamage($state, $resolution, 100));
        $this->assertSame(32, $defense->resolveDamage($state, $resolution, 50));
        $service->completePveTelegraphedEnemyAction($state);
        $this->assertNull($state->enemyTelegraphContext);
    }

    public function test_pve_telegraph_transmute_penalty_starts_after_the_predicted_action(): void
    {
        $transmute = $this->actor('transmute', 49, [
            $this->art(4901, 49, 1, '高等錬成'),
            $this->art(4905, 49, 5, '大錬成爆装'),
            $this->art(4909, 49, 9, '錬金大崩壊'),
        ]);
        $enemy = $this->enemyActor('boss', 48);
        $enemy->jobArts = $this->commandArts();
        $this->setOrigins($enemy);
        $state = $this->pveTelegraph($transmute, $enemy);
        $service = app(JobArtV2UltimateCounterplayService::class);
        $rankFive = $transmute->jobArts[1];

        $this->assertTrue($service->isResponseCandidate($transmute, $state, $rankFive));
        $this->beginAction($transmute, $state);
        $service->beginJobArtCast($transmute, $state, $rankFive);
        $service->completeJobArtCast($transmute, $enemy, $state, $rankFive, HitResult::HIT);
        $this->assertSame(0, $enemy->jobArtV2UltimateCounterplayState()->resourceGainPenaltyCharges);

        $service->completePveTelegraphedEnemyAction($state);
        $this->assertSame(2, $enemy->jobArtV2UltimateCounterplayState()->resourceGainPenaltyCharges);
        $enemy->configureResource('command_points', 12);
        $this->assertSame(3, app(JobArtV2ProgressionService::class)->modifyIncomingResourceGain($enemy, 'command_points', 4));
        $this->assertSame(3, app(JobArtV2ProgressionService::class)->modifyIncomingResourceGain($enemy, 'command_points', 4));
    }

    public function test_pve_telegraph_does_not_offer_unruled_sp_or_resource_fallbacks(): void
    {
        $aim = $this->actor('aim', 55, [$this->art(4005, 4, 5, '狙い撃ち')]);
        $plainEnemy = $this->enemyActor('plain', 999);
        $state = $this->pveTelegraph($aim, $plainEnemy);
        $service = app(JobArtV2UltimateCounterplayService::class);

        $this->assertFalse($service->isResponseCandidate($aim, $state, $aim->jobArts[0]));

        $transmute = $this->actor('transmute', 49, [$this->art(4905, 49, 5, '大錬成爆装')]);
        $state = $this->pveTelegraph($transmute, $plainEnemy);
        $this->assertFalse($service->isResponseCandidate($transmute, $state, $transmute->jobArts[0]));
    }

    public function test_shared_battle_support_wires_actual_cast_resource_and_preparation_lifecycle(): void
    {
        [$owner, $target] = $this->actors();
        $state = new BattleState($owner, $target, 'pvp');
        $support = app(JobArtBattleSupportService::class);
        $owner->configureResource('command_points', 12);
        $owner->setResource('command_points', 4);
        $rankFive = $this->commandArt(5);

        $support->beginAction($owner, $state);
        $this->assertTrue($support->consumeAndMarkUse($owner, $state, $rankFive));
        $support->completeJobArtCast($owner, $state, $rankFive, HitResult::MISS, $target);
        $support->finishAction($owner, $state);

        $this->assertSame(0, $owner->getResource('command_points'));
        $this->assertTrue($owner->jobArtV2UltimateCounterplayState()->mainRankFiveEstablished);
        $this->assertNull($owner->jobArtV2UltimateCounterplayState()->preparation);

        $owner->setResource('command_points', 12);
        $support->beginAction($owner, $state);
        $support->finishAction($owner, $state);
        $this->assertTrue($owner->jobArtV2UltimateCounterplayState()->preparation?->isPreparing());

        $support->beginAction($target, $state);
        $support->finishAction($target, $state);
        $this->assertTrue($owner->jobArtV2UltimateCounterplayState()->preparation?->isReady());
    }

    public function test_catalog_is_exact_and_slot_condition_reports_preparing_only(): void
    {
        $catalog = app(JobArtV2UltimateCounterplayCatalog::class);
        $this->assertSame(
            JobArtV2UltimateCounterplayCatalog::HUNT_CANCEL,
            $catalog->effectFor($this->huntArt(5)),
        );
        $sameNameSpecial = $this->huntArt(5);
        $sameNameSpecial->skill_type = 'special';
        $this->assertNull($catalog->forArt($sameNameSpecial));

        [$owner, $target] = $this->actors();
        $state = new BattleState($owner, $target, 'pvp');
        $this->forcePreparing($target, $state, 'hunt', 'hunt');
        $conditions = app(\App\Services\JobArtV2SlotConditionCatalog::class);
        $this->assertTrue($conditions->matches('opponent_ultimate_preparing', $owner, $state));
        $target->jobArtV2UltimateCounterplayState()->preparation?->markReady();
        $this->assertFalse($conditions->matches('opponent_ultimate_preparing', $owner, $state));
    }

    public function test_cards_and_hud_explain_the_actual_counterplay_and_preparation_state(): void
    {
        [$owner, $target] = $this->actors();
        $owner->currentJobId = 66;
        $owner->jobArts = $this->guardArts();
        $this->setOrigins($owner);
        $state = new BattleState($owner, $target, 'pvp');
        $owner->configureResource('holy_guard', 12);
        $owner->setResource('holy_guard', 12);
        $this->forcePreparing($owner, $state, 'guard', 'holy_guard');

        $presenter = app(JobArtV2LoadoutPresenter::class);
        $guardCard = $presenter->forArt(66, $this->guardArt(5), $owner->jobArts);
        $ultimateCard = $presenter->forArt(66, $this->guardArt(9), $owner->jobArts);
        $unselectedCandidate = $presenter->forArt(66, $this->guardArt(5));
        $techCandidate = $presenter->forArt(62, $this->guardArt(5), [
            $this->pierceArt(1),
            $this->pierceArt(5),
            $this->pierceArt(9),
            $this->guardArt(5),
            $this->commandArt(1),
        ]);
        $this->assertStringContainsString('35%軽減', implode(' ', $guardCard['effect_texts']));
        $this->assertStringContainsString('35%軽減', implode(' ', $unselectedCandidate['effect_texts']));
        $this->assertStringNotContainsString('対人戦・主／副系譜の対奥義', implode(' ', $techCandidate['effect_texts']));
        $this->assertStringContainsString('資源が必要量に達すると予告', implode(' ', $ultimateCard['effect_texts']));
        $this->assertStringNotContainsString('連携を1回', implode(' ', $ultimateCard['effect_texts']));

        $hud = app(JobArtV2BattleHudService::class)->present($state);
        $this->assertSame(
            '奥義予告中（相手の応答待ち）',
            collect($hud['actors'][0]['resources'])->firstWhere('key', 'holy_guard')['status_label'],
        );
    }

    public function test_counter_intercept_reduces_all_ultimate_hits_and_grants_one_counter_focus(): void
    {
        [$owner] = $this->actors();
        $counter = $this->actor('counter', 28, [$this->art(2805, 28, 5, '無拍子')]);
        $state = new BattleState($owner, $counter, 'pvp');
        $service = app(JobArtV2UltimateCounterplayService::class);
        $this->forcePreparing($owner, $state, 'command', 'command_points');
        $owner->setResource('command_points', 12);

        $this->beginAction($counter, $state);
        $service->beginJobArtCast($counter, $state, $counter->jobArts[0]);
        $service->finishAction($counter, $state);

        $this->beginAction($owner, $state);
        $service->beginJobArtCast($owner, $state, $this->commandArt(9));
        $resolution = new DirectAttackResolution(
            $state->currentSourceActionId(), $owner, $counter, HitResult::HIT,
            'physical', true, BattleActionType::JOB_ART,
        );
        $defense = app(JobArtV2DefenseService::class);

        $this->assertSame(80, $defense->resolveDamage($state, $resolution, 100));
        $this->assertSame(40, $defense->resolveDamage($state, $resolution, 50));
        $this->assertSame(1.20, $counter->jobArtV2PreparedEffect('counter_focus')?->multiplier);
        $this->assertSame(1, $counter->jobArtV2PreparedEffect('counter_focus')?->charges);
    }

    public function test_eclipse_backlash_is_nonlethal_after_next_ultimate_and_expires_on_other_action(): void
    {
        [$owner] = $this->actors();
        $eclipse = $this->actor('eclipse', 30, [$this->art(3005, 30, 5, '暗黒剣')]);
        $state = new BattleState($owner, $eclipse, 'pvp');
        $service = app(JobArtV2UltimateCounterplayService::class);
        $this->forcePreparing($owner, $state, 'command', 'command_points');
        $owner->setResource('command_points', 12);

        $this->beginAction($eclipse, $state);
        $service->beginJobArtCast($eclipse, $state, $eclipse->jobArts[0]);
        $service->completeJobArtCast($eclipse, $owner, $state, $eclipse->jobArts[0], HitResult::HIT);
        $service->finishAction($eclipse, $state);
        $owner->hp = 400;
        $this->beginAction($owner, $state);
        $service->beginJobArtCast($owner, $state, $this->commandArt(9));
        $service->finishAction($owner, $state);
        $this->assertSame(1, $owner->hp);
        $this->assertNull($owner->jobArtV2UltimateCounterplayState()->eclipseBacklash);

        $owner->hp = 10_000;
        $owner->jobArtV2UltimateCounterplayState()->eclipseBacklash = new \App\Services\JobArtV2UltimateCycleEffectState(
            'source', 2, JobArtV2UltimateCounterplayCatalog::ECLIPSE_BACKLASH, 0.05,
        );
        $this->action($owner, $state, fn () => null, $service);
        $this->assertSame(10_000, $owner->hp);
        $this->assertNull($owner->jobArtV2UltimateCounterplayState()->eclipseBacklash);
    }

    public function test_eclipse_backlash_never_revives_an_actor_already_reduced_to_zero_hp(): void
    {
        [$owner, $target] = $this->actors();
        $state = new BattleState($owner, $target, 'pvp');
        $service = app(JobArtV2UltimateCounterplayService::class);
        $preparation = $this->forceReady($owner, 'command', 'command_points');
        $owner->setResource('command_points', 12);
        $owner->hp = 0;
        $owner->jobArtV2UltimateCounterplayState()->eclipseBacklash =
            new \App\Services\JobArtV2UltimateCycleEffectState(
                'source',
                $preparation->cycleId,
                JobArtV2UltimateCounterplayCatalog::ECLIPSE_BACKLASH,
                0.05,
            );

        $this->beginAction($owner, $state);
        $service->beginJobArtCast($owner, $state, $this->commandArt(9));
        $service->finishAction($owner, $state);

        $this->assertSame(0, $owner->hp);
        $this->assertNull($owner->jobArtV2UltimateCounterplayState()->eclipseBacklash);
    }

    public function test_pierce_opening_applies_fifteen_percent_and_fifty_percent_def_ignore_without_cancel(): void
    {
        [$owner] = $this->actors();
        $piercer = $this->actor('piercer', 32, [$this->art(3205, 32, 5, 'ドラゴンダイブ')]);
        $state = new BattleState($owner, $piercer, 'pvp');
        $service = app(JobArtV2UltimateCounterplayService::class);
        $preparation = $this->forcePreparing($owner, $state, 'command', 'command_points');

        $this->beginAction($piercer, $state);
        $service->beginJobArtCast($piercer, $state, $piercer->jobArts[0]);
        $execution = clone $piercer->jobArts[0];
        $service->applyForExecution($piercer, $owner, $state, $piercer->jobArts[0], $execution);

        $this->assertSame(1.15, $execution->getAttribute('job_art_v2_target_damage_multiplier'));
        $this->assertSame(50, $execution->getAttribute('job_art_v2_defense_ignore_percent'));
        $this->assertSame('def', $execution->getAttribute('job_art_v2_defense_stat'));
        $this->assertSame($preparation, $owner->jobArtV2UltimateCounterplayState()->preparation);
    }

    public function test_aim_hit_removes_three_percent_max_sp_and_keeps_preparation(): void
    {
        [$owner] = $this->actors();
        $aim = $this->actor('aim', 65, [$this->art(4005, 4, 5, '狙い撃ち')]);
        $state = new BattleState($owner, $aim, 'pvp');
        $service = app(JobArtV2UltimateCounterplayService::class);
        $preparation = $this->forcePreparing($owner, $state, 'command', 'command_points');

        $this->beginAction($aim, $state);
        $service->beginJobArtCast($aim, $state, $aim->jobArts[0]);
        $service->completeJobArtCast($aim, $owner, $state, $aim->jobArts[0], HitResult::HIT);

        $this->assertSame(9_700, $owner->mp);
        $this->assertSame($preparation, $owner->jobArtV2UltimateCounterplayState()->preparation);
    }

    public function test_transmute_arms_two_minus_one_gains_only_after_target_ultimate(): void
    {
        [$owner] = $this->actors();
        $transmute = $this->actor('transmute', 49, [$this->art(4905, 49, 5, '大錬成爆装')]);
        $state = new BattleState($owner, $transmute, 'pvp');
        $service = app(JobArtV2UltimateCounterplayService::class);
        $progression = app(JobArtV2ProgressionService::class);
        $this->forcePreparing($owner, $state, 'command', 'command_points');
        $owner->setResource('command_points', 12);

        $this->beginAction($transmute, $state);
        $service->beginJobArtCast($transmute, $state, $transmute->jobArts[0]);
        $service->completeJobArtCast($transmute, $owner, $state, $transmute->jobArts[0], HitResult::HIT);
        $service->finishAction($transmute, $state);
        $this->assertSame(0, $owner->jobArtV2UltimateCounterplayState()->resourceGainPenaltyCharges);

        $this->beginAction($owner, $state);
        $service->beginJobArtCast($owner, $state, $this->commandArt(9));
        $service->finishAction($owner, $state);
        $owner->setResource('command_points', 0);
        $this->assertSame(3, $progression->modifyIncomingResourceGain($owner, 'command_points', 4));
        $this->assertSame(3, $progression->modifyIncomingResourceGain($owner, 'command_points', 4));
        $this->assertSame(4, $progression->modifyIncomingResourceGain($owner, 'command_points', 4));
        $this->assertSame(0, $owner->jobArtV2UltimateCounterplayState()->resourceGainPenaltyCharges);
    }

    public function test_break_consumes_one_mark_and_one_registered_preparation_but_keeps_ultimate_preparation(): void
    {
        [$owner] = $this->actors();
        $breaker = $this->actor('breaker', 33, [$this->art(3305, 33, 5, '羅刹連撃')]);
        $state = new BattleState($owner, $breaker, 'pvp');
        $service = app(JobArtV2UltimateCounterplayService::class);
        $progression = app(JobArtV2ProgressionService::class);
        $preparation = $this->forcePreparing($owner, $state, 'command', 'command_points');
        $ownerKey = 'actor:'.spl_object_id($breaker);
        $owner->jobArtV2ProgressionState()->breakMarks[$ownerKey] = 1;
        $owner->replaceJobArtV2PreparedEffect(new JobArtV2PreparedEffectState(
            'ultimate_charge', 1.20, 1, null, 1, 1, 1, 'command', [9], false, 'ultimate_charge', null,
        ));

        $this->assertTrue($service->isResponseCandidate($breaker, $state, $breaker->jobArts[0]));
        $this->beginAction($breaker, $state);
        $service->beginJobArtCast($breaker, $state, $breaker->jobArts[0]);
        $service->completeJobArtCast($breaker, $owner, $state, $breaker->jobArts[0], HitResult::HIT);

        $this->assertSame(0, $progression->breakMarkCountFor($owner, $breaker));
        $this->assertNull($owner->jobArtV2PreparedEffect('ultimate_charge'));
        $this->assertSame($preparation, $owner->jobArtV2UltimateCounterplayState()->preparation);
    }

    public function test_break_only_targets_live_preparations_for_the_announced_main_lineage(): void
    {
        [$owner] = $this->actors();
        $breaker = $this->actor('breaker', 33, [$this->art(3305, 33, 5, '羅刹連撃')]);
        $state = new BattleState($owner, $breaker, 'pvp');
        $service = app(JobArtV2UltimateCounterplayService::class);
        $this->forcePreparing($owner, $state, 'command', 'command_points');
        $owner->jobArtV2ProgressionState()->breakMarks['actor:'.spl_object_id($breaker)] = 1;
        $owner->replaceJobArtV2PreparedEffect(new JobArtV2PreparedEffectState(
            'counter_focus', 1.20, 1, null, 1, 1, 1, 'counter', [9], false, 'counter_focus', null,
        ));
        $owner->replaceJobArtV2PreparedEffect(new JobArtV2PreparedEffectState(
            'expired_command_focus', 1.20, 1, 0, 1, 1, 1, 'command', [9], false, 'command_focus', null,
        ));

        $this->assertFalse($service->isResponseCandidate($breaker, $state, $breaker->jobArts[0]));

        $owner->replaceJobArtV2PreparedEffect(new JobArtV2PreparedEffectState(
            'command_focus', 1.20, 1, null, 1, 1, 1, 'command', [9], false, 'command_focus', null,
        ));
        $this->assertTrue($service->isResponseCandidate($breaker, $state, $breaker->jobArts[0]));
    }

    public function test_recording_normal_mitigation_does_not_create_counterplay_state_when_gate_is_off(): void
    {
        [$attacker, $defender] = $this->actors();
        $state = new BattleState($attacker, $defender, 'pve');
        $this->beginAction($attacker, $state);
        $resolution = new DirectAttackResolution(
            $state->currentSourceActionId(),
            $attacker,
            $defender,
            HitResult::HIT,
            'physical',
            true,
            BattleActionType::JOB_ART,
        );

        app(JobArtV2UltimateCounterplayService::class)
            ->recordUltimateMitigation($defender, $state, $resolution, 20);

        $this->assertNull($defender->existingJobArtV2UltimateCounterplayState());
    }

    public function test_champ_finishes_the_action_only_after_resolved_damage_is_applied(): void
    {
        $source = file_get_contents(app_path('Services/ChampBattleService.php'));
        $this->assertIsString($source);
        $champActionStart = strpos($source, 'private function champAction(');
        $normalActionStart = strpos($source, 'private function normalAttackWithResource(', $champActionStart);
        $champAction = substr($source, $champActionStart, $normalActionStart - $champActionStart);
        $this->assertStringNotContainsString('finishAction(', $champAction);

        $damagePosition = strpos($source, 'applyResolvedDamage(', strpos($source, 'private function runBattle('));
        $finishPosition = strpos($source, 'finishAction(', $damagePosition);
        $this->assertNotFalse($damagePosition);
        $this->assertNotFalse($finishPosition);
        $this->assertGreaterThan($damagePosition, $finishPosition);
    }

    public function test_field_suppression_preserves_exact_master_base_and_skips_role_progression(): void
    {
        [$owner] = $this->actors();
        $field = $this->actor('field', 53, [$this->art(5305, 53, 5, '星詠みの光')]);
        $state = new BattleState($owner, $field, 'pvp');
        $service = app(JobArtV2UltimateCounterplayService::class);
        $this->forcePreparing($owner, $state, 'command', 'command_points');
        $owner->setResource('command_points', 12);

        $this->beginAction($field, $state);
        $service->beginJobArtCast($field, $state, $field->jobArts[0]);
        $service->finishAction($field, $state);

        $ultimate = $this->commandArt(9);
        $ultimate->effect_template = 'DAMAGE_BUFF';
        $ultimate->damage_type = 'physical';
        $ultimate->power = 321;
        $ultimate->power_multiplier = 3.21;
        $ultimate->hit_count = 2;
        $ultimate->setAttribute('atk_self_buff_percent', 17);
        $owner->replaceJobArtV2PreparedEffect(new JobArtV2PreparedEffectState(
            'command_focus', 1.50, 1, null, 1, 1, 1, 'command', [9], false, 'command_focus', null,
        ));

        $this->beginAction($owner, $state);
        $service->beginJobArtCast($owner, $state, $ultimate);
        app(\App\Services\JobArtV2RoleEffectService::class)->beginJobArtCast($owner, $state, $ultimate);
        $execution = app(JobArtBattleSupportService::class)->skillForExecution($owner, $ultimate, $state, $field);

        $this->assertTrue($service->lineageEffectsSuppressedForCurrentAction($owner, $state, $ultimate));
        $this->assertSame('DAMAGE_BUFF', $execution->effect_template);
        $this->assertSame('physical', $execution->damage_type);
        $this->assertSame(321, (int) $execution->power);
        $this->assertSame(2, (int) $execution->hit_count);
        $this->assertSame(17, $execution->getAttribute('atk_self_buff_percent'));
        $this->assertNotNull($owner->jobArtV2PreparedEffect('command_focus'));
        $this->assertSame(1.0, $state->jobArtV2RoleAction()['role_damage_multiplier']);
    }

    public function test_field_suppression_central_gate_skips_semantics_defense_penetration_sp_and_break_hooks(): void
    {
        $target = $this->actor('target', 48, $this->commandArts());
        $support = app(JobArtBattleSupportService::class);

        // Effect semantics + guard/defense hook.
        $guardUltimate = $this->art(6609, 66, 9, '聖冠アイギスロード');
        $guardUltimate->effect_template = 'DAMAGE_BUFF';
        $guardUltimate->damage_type = 'physical';
        $guardUltimate->power = 333;
        $guard = $this->actor('guard', 66, [$guardUltimate]);
        $guardState = new BattleState($guard, $target, 'pvp');
        $this->armSuppressedReadyUltimate($guard, $guardState, 'guard', 'holy_guard');
        $support->beginAction($guard, $guardState);
        $this->assertTrue($support->consumeAndMarkUse($guard, $guardState, $guardUltimate));
        $guardExecution = $support->skillForExecution($guard, $guardUltimate, $guardState, $target);
        $this->assertSame('DAMAGE_BUFF', $guardExecution->effect_template);
        $this->assertSame('physical', $guardExecution->damage_type);
        $this->assertSame(333, (int) $guardExecution->power);
        $this->assertNull($guard->jobArtV2GuardState());

        // Penetration stance begin/complete and defense override hooks.
        $pierceUltimate = $this->art(6209, 62, 9, '竜冠天穿槍');
        $pierce = $this->actor('pierce', 62, [$pierceUltimate]);
        $pierce->setPiercingStance(true);
        $pierceState = new BattleState($pierce, $target, 'pvp');
        $this->armSuppressedReadyUltimate($pierce, $pierceState, 'pierce', 'dragon_force');
        $support->beginAction($pierce, $pierceState);
        $this->assertTrue($support->consumeAndMarkUse($pierce, $pierceState, $pierceUltimate));
        $this->assertTrue($pierce->hasPiercingStance());
        $this->assertSame(
            ['def' => null, 'spr' => null, 'penetration_rate' => null],
            $support->defenseOverrides($pierce, $target, $pierceState, $pierceUltimate),
        );
        $support->completeJobArtCast($pierce, $pierceState, $pierceUltimate, HitResult::HIT, $target);
        $this->assertTrue($pierce->hasPiercingStance());

        // Crown aim SP pressure hook.
        $aimUltimate = $this->art(6509, 65, 9, '鋼冠グラビトンコア');
        $aim = $this->actor('aim', 65, [$aimUltimate]);
        $aimState = new BattleState($aim, $target, 'pvp');
        $this->armSuppressedReadyUltimate($aim, $aimState, 'aim', 'aim');
        $target->mp = 10_000;
        $support->beginAction($aim, $aimState);
        $this->assertTrue($support->consumeAndMarkUse($aim, $aimState, $aimUltimate));
        $support->completeJobArtCast($aim, $aimState, $aimUltimate, HitResult::HIT, $target);
        $this->assertSame(10_000, $target->mp);
        $this->assertSame([], $aimState->spPressureResults());

        // Crown break timed-debuff hook.
        $breakUltimate = $this->art(6809, 68, 9, '雷冠天鳴掌');
        $breaker = $this->actor('breaker', 68, [$breakUltimate]);
        $breakState = new BattleState($breaker, $target, 'pvp');
        $this->armSuppressedReadyUltimate($breaker, $breakState, 'break', 'break');
        $target->replaceBreakDebuffState(null);
        $support->beginAction($breaker, $breakState);
        $this->assertTrue($support->consumeAndMarkUse($breaker, $breakState, $breakUltimate));
        $support->completeJobArtCast($breaker, $breakState, $breakUltimate, HitResult::HIT, $target);
        $this->assertNull($target->breakDebuffState());
        $this->assertSame([], $breakState->breakDebuffResults());
    }

    /** @return array{BattleActor, BattleActor} */
    private function actors(): array
    {
        $command = $this->actor('command', 48, $this->commandArts());
        $hunt = $this->actor('hunt', 54, $this->huntArts());

        return [$command, $hunt];
    }

    private function actor(string $name, int $currentJobId, array $arts): BattleActor
    {
        $actor = new BattleActor($name, true, [
            'hp' => 10_000,
            'max_hp' => 10_000,
            'mp' => 10_000,
            'max_mp' => 10_000,
            'current_job_id' => $currentJobId,
        ]);
        $actor->jobArts = $arts;
        $actor->jobArtActivationPolicy = 'aggressive';
        $this->setOrigins($actor);

        return $actor;
    }

    private function enemyActor(string $name, int $currentJobId): BattleActor
    {
        return new BattleActor($name, false, [
            'hp' => 10_000,
            'max_hp' => 10_000,
            'mp' => $currentJobId === 999 ? 0 : 10_000,
            'max_mp' => $currentJobId === 999 ? 0 : 10_000,
            'current_job_id' => $currentJobId,
        ]);
    }

    private function pveTelegraph(
        BattleActor $player,
        BattleActor $enemy,
        bool $canBeGuarded = true,
    ): BattleState {
        $state = new BattleState($player, $enemy, 'boss');
        $state->pendingEnemyActionId = 101;
        $state->pendingEnemyActionTurns = 1;
        $state->enemyTelegraphContext = [
            'cycle_id' => 1,
            'action_id' => 101,
            'action_name' => '予告大技',
            'can_be_guarded' => $canBeGuarded,
            'delayed' => false,
            'executing' => false,
        ];

        return $state;
    }

    private function setOrigins(BattleActor $actor): void
    {
        foreach ($actor->jobArts as $skill) {
            $actor->jobArtOrigins[(int) $skill->id] = (int) $skill->job_id === (int) $actor->currentJobId
                ? 'current'
                : 'inherited';
            $actor->jobArtRates[(int) $skill->id] = 1.0;
            $actor->jobArtConditions[(int) $skill->id] = 'always';
        }
    }

    /** @return list<Skill> */
    private function commandArts(): array
    {
        return [$this->commandArt(1), $this->commandArt(5), $this->commandArt(9)];
    }

    private function commandArt(int $rank): Skill
    {
        return $this->art(4800 + $rank, 48, $rank, match ($rank) {
            1 => '先読みの布陣', 5 => '王戦の号令', default => '覇王大戦略',
        });
    }

    /** @return list<Skill> */
    private function huntArts(): array
    {
        return [$this->huntArt(1), $this->huntArt(5), $this->huntArt(9)];
    }

    private function huntArt(int $rank): Skill
    {
        return $this->art(5400 + $rank, 54, $rank, match ($rank) {
            1 => '影糸仕込み', 5 => '影縫い乱舞', default => '影牢・無明縛',
        });
    }

    /** @return list<Skill> */
    private function guardArts(): array
    {
        return [$this->guardArt(1), $this->guardArt(5), $this->guardArt(9)];
    }

    private function guardArt(int $rank): Skill
    {
        return $this->art(6600 + $rank, $rank === 5 ? 15 : 66, $rank, match ($rank) {
            1 => '聖冠加護', 5 => 'ガーディアンブロウ', default => '聖冠アイギスロード',
        });
    }

    private function pierceArt(int $rank): Skill
    {
        return $this->art(6200 + $rank, 62, $rank, match ($rank) {
            1 => '竜冠の槍印', 5 => '竜冠穿槍', default => '竜冠天穿槍',
        });
    }

    private function art(int $id, int $jobId, int $rank, string $name): Skill
    {
        $skill = new Skill([
            'name' => $name,
            'skill_type' => 'job_art',
            'job_id' => $jobId,
            'learn_rank' => $rank,
            'art_cost' => match ($rank) { 1 => 1, 5 => 2, default => 3 },
            'activation_rate' => match ($rank) { 1 => 35, 5 => 38, default => 50 },
            'sp_cost_fixed' => 0,
            'effect_template' => 'PHYSICAL_DAMAGE',
            'power' => 100,
            'hit_count' => 1,
        ]);
        $skill->setAttribute('id', $id);

        return $skill;
    }

    private function beginAction(BattleActor $actor, BattleState $state): void
    {
        $sourceActionId = $state->beginSourceAction();
        $state->beginJobArtV2RoleAction($sourceActionId, [
            'actor_key' => $state->actorKey($actor),
            'source_action_id' => $sourceActionId,
        ]);
    }

    private function action(
        BattleActor $actor,
        BattleState $state,
        \Closure $during,
        JobArtV2UltimateCounterplayService $service,
    ): void {
        $this->beginAction($actor, $state);
        $during();
        $service->finishAction($actor, $state);
    }

    private function forcePreparing(
        BattleActor $actor,
        BattleState $state,
        string $lineage,
        string $resourceKey,
    ): JobArtV2UltimatePreparationState {
        $actor->configureResource($resourceKey, 12);
        $preparation = new JobArtV2UltimatePreparationState(1, $lineage, $resourceKey, 1);
        $actor->jobArtV2UltimateCounterplayState()->preparation = $preparation;
        $actor->jobArtV2UltimateCounterplayState()->mainRankFiveEstablished = true;

        return $preparation;
    }

    private function forceReady(
        BattleActor $actor,
        string $lineage,
        string $resourceKey,
    ): JobArtV2UltimatePreparationState {
        $actor->configureResource($resourceKey, 12);
        $preparation = new JobArtV2UltimatePreparationState(1, $lineage, $resourceKey, 1);
        $preparation->markReady();
        $actor->jobArtV2UltimateCounterplayState()->preparation = $preparation;
        $actor->jobArtV2UltimateCounterplayState()->mainRankFiveEstablished = true;

        return $preparation;
    }

    private function armSuppressedReadyUltimate(
        BattleActor $actor,
        BattleState $state,
        string $lineage,
        string $resourceKey,
    ): void {
        $preparation = $this->forceReady($actor, $lineage, $resourceKey);
        $actor->setResource($resourceKey, 12);
        $actor->jobArtV2UltimateCounterplayState()->lineageSuppression =
            new \App\Services\JobArtV2UltimateCycleEffectState(
                $state->actorKey($state->enemy),
                $preparation->cycleId,
                JobArtV2UltimateCounterplayCatalog::FIELD_SUPPRESSION,
            );
    }

    private function selection(array $rolls): JobArtV2SelectionService
    {
        $random = new class($rolls) extends JobArtV2RandomSource
        {
            private int $index = 0;

            public function __construct(private readonly array $rolls) {}

            public function percentRoll(): int
            {
                return $this->rolls[$this->index++] ?? 1;
            }
        };

        return new JobArtV2SelectionService(
            $random,
            new JobArtV2FinisherConditionProvider(),
            app(JobArtV2SpCostCalculator::class),
            app(JobArtV2BattleRules::class),
        );
    }
}
