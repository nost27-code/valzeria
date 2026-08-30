<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\BattleActionType;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageApplicationRequest;
use App\Services\Battle\DamageApplicationResult;
use App\Services\Battle\DamageApplicationService;
use App\Services\Battle\DamageCalculator;
use App\Services\Battle\DamageSourceType;
use App\Services\Battle\DirectAttackResolution;
use App\Services\Battle\HitResult;
use App\Services\JobArtBattleSupportService;
use App\Services\JobArtV2CounterStanceState;
use App\Services\JobArtV2BattleHudService;
use App\Services\JobArtV2DefenseService;
use App\Services\JobArtV2FeatureGate;
use App\Services\JobArtV2GuardState;
use App\Services\JobArtV2LoadoutPresenter;
use App\Services\JobArtV2ParryRandomSource;
use App\Services\JobArtV2PrototypeCatalog;
use App\Services\JobArtV2ResourceService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class JobArtV2CounterGuardServiceTest extends TestCase
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

    public function test_counter_stance_keeps_four_turns_skips_cast_turn_and_refreshes(): void
    {
        [$counter, , $state] = $this->battle(60);
        $defense = $this->defense(new FixedParryRandomSource([100]));
        $state->turnCount = 1;

        $this->cast($counter, $state, $this->art(60, 1), $defense);
        $this->assertSame(4, $counter->getResource('sword_momentum'));
        $this->assertSame(4, $counter->counterStanceState()?->remainingRounds);

        $defense->endRound($state);
        $this->assertSame(4, $counter->counterStanceState()?->remainingRounds);

        $state->turnCount = 2;
        $defense->endRound($state);
        $this->assertSame(3, $counter->counterStanceState()?->remainingRounds);

        $this->cast($counter, $state, $this->art(60, 1), $defense);
        $this->assertSame(8, $counter->getResource('sword_momentum'));
        $this->assertSame(4, $counter->counterStanceState()?->remainingRounds);
        $defense->endRound($state);
        $this->assertSame(4, $counter->counterStanceState()?->remainingRounds);

        $state->turnCount = 3;
        $defense->endRound($state);
        $this->assertSame(3, $counter->counterStanceState()?->remainingRounds);
        $state->turnCount = 4;
        $defense->endRound($state);
        $this->assertSame(2, $counter->counterStanceState()?->remainingRounds);
        $state->turnCount = 5;
        $defense->endRound($state);
        $this->assertSame(1, $counter->counterStanceState()?->remainingRounds);
        $state->turnCount = 6;
        $defense->endRound($state);
        $this->assertNull($counter->counterStanceState());
        $this->assertSame(JobArtV2DefenseService::COUNTER_EVENT_EXPIRED, collect($state->counterStanceEvents())->last()['event']);
    }

    public function test_mikiri_breath_grants_a_one_round_twenty_five_percent_parry_stance(): void
    {
        [$actor, , $state] = $this->battle(1);
        $defense = $this->defense(new FixedParryRandomSource([100]));
        $skill = $this->art(1, 1);
        $skill->name = '見切りの呼吸';
        $skill->power = 90;
        $skill->power_multiplier = 0.90;

        $this->cast($actor, $state, $skill, $defense);

        $this->assertSame(1, $actor->counterStanceState()?->remainingRounds);
        $this->assertSame(0.25, $actor->counterStanceState()?->parryRate);
    }

    public function test_parry_rolls_once_for_a_multihit_action_and_only_grants_the_parry_event(): void
    {
        [$counter, $attacker, $state] = $this->battle(60);
        $random = new FixedParryRandomSource([20]);
        $defense = $this->defense($random);
        $application = new DamageApplicationService($defense);
        $this->cast($counter, $state, $this->art(60, 1), $defense);

        $this->resources()->beginAction($attacker, $state);
        $first = $this->applyDirect($application, $state, $attacker, $counter, 120, 'physical', HitResult::HIT, 1, 2);
        $second = $this->applyDirect($application, $state, $attacker, $counter, 80, 'physical', HitResult::HIT, 2, 2);
        $this->resources()->finishAction($attacker, $state);

        $this->assertSame([0, 0], [$first->requestedDamage, $second->requestedDamage]);
        $this->assertSame(10_000, $counter->hp);
        $this->assertSame(1, $random->calls);
        $this->assertSame(5, $counter->getResource('sword_momentum'));
        $this->assertNotNull($counter->counterStanceState());

        $parry = collect($state->parryResults())->last();
        $this->assertTrue($parry->success);
        $this->assertSame([200, 0], [$parry->damageBeforeParry, $parry->damageAfterParry]);

        $resolution = new DirectAttackResolution(
            $state->currentSourceActionId(),
            $attacker,
            $counter,
            HitResult::HIT,
            'physical',
            true,
            BattleActionType::NORMAL_ATTACK,
        );
        $this->assertSame(0, $defense->resolveDamage($state, $resolution, 10));
        $this->assertSame(HitResult::HIT, $resolution->hitResult);

        $hud = app(JobArtV2BattleHudService::class)->present($state);
        $incoming = collect($hud['actions'])->firstWhere('action_kind', 'incoming_attack');
        $this->assertNotNull($incoming);
        $this->assertSame(1, collect($incoming['changes'])->firstWhere('type', 'resource')['delta']);
        $this->assertTrue(collect($incoming['changes'])->firstWhere('type', 'parry')['success']);
    }

    public function test_counter_received_event_requires_survived_direct_hp_damage_and_is_once_per_action(): void
    {
        [$counter, $attacker, $state] = $this->battle(60);
        $random = new FixedParryRandomSource([100, 100, 100]);
        $defense = $this->defense($random);
        $application = new DamageApplicationService($defense);

        $this->resources()->beginAction($attacker, $state);
        $this->applyDirect($application, $state, $attacker, $counter, 100, 'physical', HitResult::HIT, 1, 2);
        $this->assertSame(0, $counter->getResource('sword_momentum'));
        $this->applyDirect($application, $state, $attacker, $counter, 100, 'physical', HitResult::HIT, 2, 2);
        $this->assertSame(0, $counter->getResource('sword_momentum'));
        $defense->completeDirectAttackAction($attacker, $state);
        $this->assertSame(1, $counter->getResource('sword_momentum'));
        $this->resources()->finishAction($attacker, $state);

        $this->resources()->beginAction($attacker, $state);
        $this->applyDirect($application, $state, $attacker, $counter, 100, 'magical');
        $defense->completeDirectAttackAction($attacker, $state);
        $this->assertSame(2, $counter->getResource('sword_momentum'));
        $this->resources()->finishAction($attacker, $state);

        foreach ([HitResult::MISS, HitResult::EVADE] as $hitResult) {
            $this->resources()->beginAction($attacker, $state);
            $excluded = new DirectAttackResolution(
                $state->currentSourceActionId(),
                $attacker,
                $counter,
                $hitResult,
                'physical',
                true,
                BattleActionType::NORMAL_ATTACK,
            );
            $application->apply(new DamageApplicationRequest(
                sourceActor: $attacker,
                targetActor: $counter,
                resolvedDamage: 100,
                sourceType: DamageSourceType::NORMAL_ATTACK,
                sourceId: null,
                battleType: $state->battleType,
                hitResult: $hitResult,
                battleState: $state,
                directAttackResolution: $excluded,
            ));
            $this->resources()->finishAction($attacker, $state);
        }

        $this->resources()->beginAction($attacker, $state);
        $application->apply(new DamageApplicationRequest(
            sourceActor: $attacker,
            targetActor: $counter,
            resolvedDamage: 100,
            sourceType: DamageSourceType::OTHER,
            sourceId: null,
            battleType: $state->battleType,
            hitResult: HitResult::HIT,
            battleState: $state,
        ));
        $this->resources()->finishAction($attacker, $state);

        $this->assertSame(2, $counter->getResource('sword_momentum'));
        $this->assertSame(0, $random->calls);
    }

    public function test_counter_received_event_excludes_lethal_and_guts_outcomes(): void
    {
        foreach ([false, true] as $usesGuts) {
            [$counter, $attacker, $state] = $this->battle(60);
            $counter->hp = 50;
            $counter->gutsReady = $usesGuts;
            $defense = $this->defense(new FixedParryRandomSource([100]));
            $application = new DamageApplicationService($defense);

            $this->resources()->beginAction($attacker, $state);
            $this->applyDirect($application, $state, $attacker, $counter, 100, 'physical');
            $defense->completeDirectAttackAction($attacker, $state);
            $this->resources()->finishAction($attacker, $state);

            $this->assertSame($usesGuts ? 1 : 0, $counter->hp);
            $this->assertSame(0, $counter->getResource('sword_momentum'));
        }
    }

    public function test_counter_received_event_excludes_indirect_fixed_self_reflect_and_counter_sources(): void
    {
        foreach ([
            DamageSourceType::DOT,
            DamageSourceType::SELF_DAMAGE,
            DamageSourceType::RECOIL,
            DamageSourceType::COUNTER,
            DamageSourceType::REFLECT,
            DamageSourceType::FIXED,
            DamageSourceType::PURE,
        ] as $sourceType) {
            [$counter, $attacker, $state] = $this->battle(60);
            $application = new DamageApplicationService($this->defense(new FixedParryRandomSource([100])));

            $this->resources()->beginAction($attacker, $state);
            $application->apply(new DamageApplicationRequest(
                sourceActor: $attacker,
                targetActor: $counter,
                resolvedDamage: 100,
                sourceType: $sourceType,
                sourceId: null,
                battleType: $state->battleType,
                hitResult: HitResult::HIT,
                battleState: $state,
                directAttackResolution: new DirectAttackResolution(
                    sourceActionId: $state->currentSourceActionId(),
                    attacker: $attacker,
                    target: $counter,
                    hitResult: HitResult::HIT,
                    damageCategory: 'physical',
                    direct: true,
                    actionType: BattleActionType::CURRENT_JOB_SKILL,
                ),
            ));
            $this->resources()->finishAction($attacker, $state);

            $this->assertSame(0, $counter->getResource('sword_momentum'), $sourceType->value);
        }
    }

    public function test_counter_received_event_accepts_a_champ_style_aggregated_multihit_result(): void
    {
        [$counter, $attacker, $state] = $this->battle(60, null, 'champ');
        $defense = $this->defense(new FixedParryRandomSource([100]));
        $application = new DamageApplicationService($defense);

        $this->resources()->beginAction($attacker, $state);
        $this->applyDirect($application, $state, $attacker, $counter, 300, 'magical', HitResult::HIT, 3, 3);
        $defense->completeDirectAttackAction($attacker, $state);
        $this->resources()->finishAction($attacker, $state);

        $this->assertSame(1, $counter->getResource('sword_momentum'));
    }

    public function test_counter_received_event_finalizes_when_the_last_multihit_attempt_does_not_apply_damage(): void
    {
        [$counter, $attacker, $state] = $this->battle(60);
        $defense = $this->defense(new FixedParryRandomSource([100]));
        $application = new DamageApplicationService($defense);

        $this->resources()->beginAction($attacker, $state);
        $this->applyDirect($application, $state, $attacker, $counter, 100, 'physical', HitResult::HIT, 1, 2);
        $this->assertSame(0, $counter->getResource('sword_momentum'));

        // 2段目がMISS等でdamage適用まで来なくても、1段目の実HP減少を行動終了時に確定する。
        $state->addDamageLog('1段目で100のダメージ！');
        $defense->completeDirectAttackAction($attacker, $state);
        $this->resources()->finishAction($attacker, $state);

        $this->assertSame(1, $counter->getResource('sword_momentum'));
        $this->assertSame('1段目で100のダメージ！', $state->logs[0]);
        $this->assertStringContainsString('剣勢 +1', $state->logs[1]);
        $this->assertSame([], $state->pullDeferredDamageLogs());
    }

    public function test_parry_uses_the_twenty_percent_boundary_without_reclassifying_a_failed_hit(): void
    {
        [$counter, $attacker, $state] = $this->battle(60);
        $random = new FixedParryRandomSource([21]);
        $defense = $this->defense($random);
        $this->cast($counter, $state, $this->art(60, 1), $defense);

        $this->resources()->beginAction($attacker, $state);
        $resolution = new DirectAttackResolution(
            $state->currentSourceActionId(),
            $attacker,
            $counter,
            HitResult::HIT,
            'physical',
            true,
            BattleActionType::NORMAL_ATTACK,
        );

        $application = new DamageApplicationService($defense);
        $result = $application->apply(new DamageApplicationRequest(
            sourceActor: $attacker,
            targetActor: $counter,
            resolvedDamage: 100,
            sourceType: DamageSourceType::NORMAL_ATTACK,
            sourceId: null,
            battleType: $state->battleType,
            hitResult: HitResult::HIT,
            battleState: $state,
            directAttackResolution: $resolution,
        ));
        $this->assertSame(100, $result->requestedDamage);
        $this->assertSame(HitResult::HIT, $resolution->hitResult);
        $this->assertSame(1, $random->calls);
        $this->assertFalse($state->parryResult($counter, $state->currentSourceActionId())?->success);
        $defense->completeDirectAttackAction($attacker, $state);
        $this->resources()->finishAction($attacker, $state);
        $this->assertSame(5, $counter->getResource('sword_momentum'));
    }

    #[DataProvider('royalSwordBattlePathProvider')]
    public function test_royal_sword_formation_counters_once_with_power_ninety_in_every_battle_path(
        string $battleType,
        string $expectedDamageRoute,
    ): void {
        [$counter, $attacker, $state] = $this->battle(60, 60, $battleType);
        $random = new FixedParryRandomSource([1]);
        $calculator = new RecordingCounterDamageCalculator;
        $defense = $this->defense($random, $calculator);
        $application = new DamageApplicationService($defense);
        $counter->replaceCounterStanceState(new JobArtV2CounterStanceState(5, 1, 0.35));
        $counter->jobArtV2ProgressionState()->applyRoundState('royal_sword_formation', 5, 1);

        // 反撃から別の反撃が再帰しないことも、反撃対象側へ同じ状態を持たせて固定する。
        $attacker->replaceCounterStanceState(new JobArtV2CounterStanceState(5, 1, 0.35));
        $attacker->jobArtV2ProgressionState()->applyRoundState('royal_sword_formation', 5, 1);
        $attacker->configureResource('sword_momentum', 12);
        $attacker->setResource('sword_momentum', 7);

        $this->resources()->beginAction($attacker, $state);
        $sourceActionId = $state->currentSourceActionId();
        $this->assertNotNull($sourceActionId);

        $attackerHpBefore = $attacker->hp;
        $first = $this->applyDirect($application, $state, $attacker, $counter, 120, 'physical', HitResult::HIT, 1, 2);
        $this->assertSame(0, $first->requestedDamage);
        $attackerHpAfterFirstHit = $attacker->hp;
        $this->assertLessThan($attackerHpBefore, $attackerHpAfterFirstHit);

        // 同一source actionの2Hit目も受け流すが、反撃damageは再発生しない。
        $second = $this->applyDirect($application, $state, $attacker, $counter, 80, 'physical', HitResult::HIT, 2, 2);
        $this->assertSame(0, $second->requestedDamage);
        $this->assertSame($attackerHpAfterFirstHit, $attacker->hp);
        $this->resources()->finishAction($attacker, $state);

        $parry = $state->parryResult($counter, $sourceActionId);
        $this->assertNotNull($parry);
        $this->assertTrue($parry->success);
        $this->assertSame(90, $parry->counterPower);
        $this->assertSame($attackerHpBefore - $attackerHpAfterFirstHit, $parry->counterDamage);
        $this->assertSame([['route' => $expectedDamageRoute, 'power' => 90]], $calculator->calls);
        $this->assertSame(1, $random->calls);
        $this->assertCount(1, $state->parryResults());
        $this->assertSame(1, $counter->getResource('sword_momentum'), '完全に受け流したため、受け流し+1だけ。反撃damage自体では剣勢を得ない。');
        $this->assertSame(7, $attacker->getResource('sword_momentum'), '反撃damageではHIT時resource効果を起動しない。');
        $this->assertStringContainsString('王冠剣陣が反撃', implode('|', $state->logs));

        $hud = app(JobArtV2BattleHudService::class)->present($state);
        $incoming = collect($hud['actions'])->firstWhere('action_kind', 'incoming_attack');
        $parryChange = collect($incoming['changes'] ?? [])->firstWhere('type', 'parry');
        $this->assertSame(90, $parryChange['counter_power'] ?? null);
        $this->assertSame($parry->counterDamage, $parryChange['counter_damage'] ?? null);
    }

    public static function royalSwordBattlePathProvider(): array
    {
        return [
            'normal PvE' => ['pve', 'physical'],
            'boss' => ['boss', 'physical'],
            'tower (shared PvE pipeline)' => ['pve', 'physical'],
            'PvP' => ['pvp', 'rank'],
            'champ' => ['champ', 'duel'],
            'NPC arena' => ['arena_npc', 'rank'],
        ];
    }

    public function test_royal_sword_counter_requires_a_successful_direct_physical_parry(): void
    {
        [$counter, $attacker, $state] = $this->battle(60, 60);
        $failedRandom = new FixedParryRandomSource([36]);
        $defense = $this->defense($failedRandom);
        $counter->replaceCounterStanceState(new JobArtV2CounterStanceState(5, 1, 0.35));
        $counter->jobArtV2ProgressionState()->applyRoundState('royal_sword_formation', 5, 1);

        $this->resources()->beginAction($attacker, $state);
        $failedSourceActionId = $state->currentSourceActionId();
        $failed = new DirectAttackResolution(
            $failedSourceActionId,
            $attacker,
            $counter,
            HitResult::HIT,
            'physical',
            true,
            BattleActionType::NORMAL_ATTACK,
        );
        $this->assertSame(100, $defense->resolveDamage($state, $failed, 100));
        $this->assertSame(10_000, $attacker->hp);
        $this->assertSame(0, $state->parryResult($counter, $failedSourceActionId)?->counterDamage);
        $this->resources()->finishAction($attacker, $state);

        $excludedRandom = new FixedParryRandomSource([1, 1, 1, 1, 1]);
        $defense = $this->defense($excludedRandom);
        foreach ([
            ['magical direct', 'magical', true, HitResult::HIT, DamageSourceType::NORMAL_ATTACK],
            ['miss', 'physical', true, HitResult::MISS, DamageSourceType::NORMAL_ATTACK],
            ['DoT', 'physical', false, HitResult::HIT, DamageSourceType::DOT],
            ['self damage', 'physical', false, HitResult::HIT, DamageSourceType::SELF_DAMAGE],
            ['reflect', 'physical', false, HitResult::HIT, DamageSourceType::REFLECT],
            ['counter recursion', 'physical', false, HitResult::HIT, DamageSourceType::COUNTER],
        ] as [$label, $category, $direct, $hitResult, $sourceType]) {
            $this->resources()->beginAction($attacker, $state);
            $sourceActionId = $state->currentSourceActionId();
            $excluded = DirectAttackResolution::fromDamageSource(
                $sourceActionId,
                $attacker,
                $counter,
                $hitResult,
                $category,
                $direct,
                $sourceType,
            );
            $this->assertSame(100, $defense->resolveDamage($state, $excluded, 100), $label);
            $this->assertSame(10_000, $attacker->hp, $label);
            $this->resources()->finishAction($attacker, $state);
        }
        $this->assertSame(0, $excludedRandom->calls);
    }

    public function test_royal_sword_counter_stops_after_the_five_turn_formation_expires(): void
    {
        [$counter, $attacker, $state] = $this->battle(60, 60);
        $random = new FixedParryRandomSource([1]);
        $defense = $this->defense($random);
        $state->turnCount = 1;
        $counter->replaceCounterStanceState(new JobArtV2CounterStanceState(5, 1, 0.35));
        $counter->jobArtV2ProgressionState()->applyRoundState('royal_sword_formation', 5, 1);

        $defense->endRound($state);
        app(\App\Services\JobArtV2ProgressionService::class)->endRound($state);
        for ($round = 2; $round <= 6; $round++) {
            $state->turnCount = $round;
            $defense->endRound($state);
            app(\App\Services\JobArtV2ProgressionService::class)->endRound($state);
        }

        $this->assertNull($counter->counterStanceState());
        $this->assertFalse($counter->jobArtV2ProgressionState()->hasRoundState('royal_sword_formation'));
        $this->resources()->beginAction($attacker, $state);
        $resolution = new DirectAttackResolution(
            $state->currentSourceActionId(),
            $attacker,
            $counter,
            HitResult::HIT,
            'physical',
            true,
            BattleActionType::NORMAL_ATTACK,
        );
        $this->assertSame(100, $defense->resolveDamage($state, $resolution, 100));
        $this->assertSame(10_000, $attacker->hp);
        $this->assertSame(0, $random->calls);
    }

    public function test_guard_reduces_an_entire_multihit_action_once_and_records_actual_prevention(): void
    {
        [$guard, $attacker, $state] = $this->battle(66);
        $defense = $this->defense(new FixedParryRandomSource([100]));
        $application = new DamageApplicationService($defense);
        $this->cast($guard, $state, $this->art(66, 1, 'MAGICAL_DAMAGE_BUFF'), $defense);

        $this->resources()->beginAction($attacker, $state);
        $first = $this->applyDirect($application, $state, $attacker, $guard, 100, 'physical', HitResult::HIT, 1, 2);
        $second = $this->applyDirect($application, $state, $attacker, $guard, 50, 'physical', HitResult::HIT, 2, 2);
        $sourceActionId = $state->currentSourceActionId();
        $this->resources()->finishAction($attacker, $state);

        $this->assertSame([75, 37], [$first->requestedDamage, $second->requestedDamage]);
        $this->assertSame(9_888, $guard->hp);
        $this->assertNull($guard->jobArtV2GuardState());
        $this->assertSame(5, $guard->getResource('holy_guard'));
        $trace = $state->damageTrace($guard, $sourceActionId);
        $this->assertSame([150, 112, 38], [
            $trace->damageBeforeActiveGuard,
            $trace->damageAfterActiveGuard,
            $trace->preventedDamage,
        ]);

        $hud = app(JobArtV2BattleHudService::class)->present($state);
        $incoming = collect($hud['actions'])->firstWhere('action_kind', 'incoming_attack');
        $guardChange = collect($incoming['changes'])->firstWhere('type', 'active_guard');
        $this->assertSame([150, 112, 38], [$guardChange['damage_before'], $guardChange['damage_after'], $guardChange['prevented_damage']]);
    }

    public function test_guard_handles_magical_damage_stronger_priority_zero_prevention_and_parry_order(): void
    {
        [$guard, $attacker, $state] = $this->battle(66);
        $defense = $this->defense(new FixedParryRandomSource([100]));
        $application = new DamageApplicationService($defense);

        $guard->configureResource('holy_guard', 12);
        $guard->setResource('holy_guard', 12);
        $this->cast($guard, $state, $this->art(66, 9, 'MAGICAL_DAMAGE_BUFF'), $defense);
        $this->assertSame(0.45, $guard->jobArtV2GuardState()?->rate);
        $guard->setResource('holy_guard', 4);
        $this->cast($guard, $state, $this->art(66, 5, 'MAGICAL_DAMAGE_BUFF'), $defense);
        $this->assertSame(0.45, $guard->jobArtV2GuardState()?->rate);

        $this->resources()->beginAction($attacker, $state);
        $magical = $this->applyDirect($application, $state, $attacker, $guard, 100, 'magical');
        $this->assertSame(55, $magical->requestedDamage);

        $guard->replaceJobArtV2GuardState(new JobArtV2GuardState(0.20));
        $beforeResource = $guard->getResource('holy_guard');
        $this->resources()->beginAction($attacker, $state);
        $oneDamage = $this->applyDirect($application, $state, $attacker, $guard, 1, 'physical');
        $this->assertSame(1, $oneDamage->requestedDamage);
        $this->assertSame($beforeResource, $guard->getResource('holy_guard'));

        [$counter, $other, $counterState] = $this->battle(60);
        $successDefense = $this->defense(new FixedParryRandomSource([1]));
        $this->cast($counter, $counterState, $this->art(60, 1), $successDefense);
        $counter->replaceJobArtV2GuardState(new JobArtV2GuardState(0.25));
        $this->resources()->beginAction($other, $counterState);
        $resolution = new DirectAttackResolution(
            $counterState->currentSourceActionId(),
            $other,
            $counter,
            HitResult::HIT,
            'physical',
            true,
            BattleActionType::NORMAL_ATTACK,
        );
        $this->assertSame(0, $successDefense->resolveDamage($counterState, $resolution, 100));
        $this->assertSame(0.25, $counter->jobArtV2GuardState()?->rate);
        $this->assertNull($counterState->damageTrace($counter, $counterState->currentSourceActionId()));
    }

    public function test_guard_ignores_indirect_recoil_and_dot_and_container_injects_the_shared_service(): void
    {
        [$guard, $attacker, $state] = $this->battle(66);
        $guard->replaceJobArtV2GuardState(new JobArtV2GuardState(0.20));
        $this->resources()->beginAction($attacker, $state);

        $indirect = new DirectAttackResolution(
            $state->currentSourceActionId(),
            $attacker,
            $guard,
            HitResult::HIT,
            'physical',
            false,
            BattleActionType::CURRENT_JOB_SKILL,
        );
        foreach ([DamageSourceType::DOT, DamageSourceType::RECOIL, DamageSourceType::SELF_DAMAGE] as $sourceType) {
            $result = app(DamageApplicationService::class)->apply(new DamageApplicationRequest(
                sourceActor: $attacker,
                targetActor: $guard,
                resolvedDamage: 10,
                sourceType: $sourceType,
                sourceId: null,
                battleType: 'pve',
                hitResult: HitResult::HIT,
                battleState: $state,
                directAttackResolution: $indirect,
            ));
            $this->assertSame(10, $result->requestedDamage);
        }
        $this->assertSame(0.20, $guard->jobArtV2GuardState()?->rate);

        $this->resources()->beginAction($attacker, $state);
        $direct = $this->applyDirect(app(DamageApplicationService::class), $state, $attacker, $guard, 100, 'physical');
        $this->assertSame(80, $direct->requestedDamage);
        $this->assertNull($guard->jobArtV2GuardState());
    }

    public function test_rank_five_cleanse_remains_structured_but_does_not_refund_its_direct_resource_cost(): void
    {
        [$guard, , $state] = $this->battle(66);
        $defense = $this->defense(new FixedParryRandomSource([100]));
        foreach (JobArtV2DefenseService::CLEANSABLE_STATES as $key) {
            $guard->conditions[$key] = ['turns' => 3, 'rate' => 0.1];
        }
        $guard->conditions['stun'] = ['turns' => 1];
        $guard->configureResource('holy_guard', 12);
        $guard->setResource('holy_guard', 4);

        $this->cast($guard, $state, $this->art(66, 5, 'MAGICAL_DAMAGE_BUFF'), $defense);
        $this->assertSame(0, $guard->getResource('holy_guard'));
        $this->assertSame(['stun'], array_keys($guard->conditions));
        $result = collect($state->cleanseResults())->last();
        $this->assertTrue($result->success);
        $this->assertSame(7, $result->removedCount);
        $this->assertSame(JobArtV2DefenseService::CLEANSABLE_STATES, $result->removedStates);
        $this->assertSame(0.35, $guard->jobArtV2GuardState()?->rate);

        $guard->setResource('holy_guard', 4);
        $this->cast($guard, $state, $this->art(66, 5, 'MAGICAL_DAMAGE_BUFF'), $defense);
        $this->assertSame(0, $guard->getResource('holy_guard'));
        $this->assertFalse(collect($state->cleanseResults())->last()->success);
    }

    public function test_rank_costs_clamp_resources_and_rank_nine_stays_once_per_battle(): void
    {
        foreach ([60 => 'sword_momentum', 66 => 'holy_guard'] as $jobId => $resourceKey) {
            [$actor, , $state] = $this->battle($jobId);
            $defense = $this->defense(new FixedParryRandomSource([100]));
            foreach (range(1, 4) as $_) {
                $this->cast($actor, $state, $this->art($jobId, 1, $jobId === 66 ? 'MAGICAL_DAMAGE_BUFF' : 'PHYSICAL_DAMAGE'), $defense);
            }
            $this->assertSame(12, $actor->getResource($resourceKey));

            $rankFive = $this->art($jobId, 5, $jobId === 66 ? 'MAGICAL_DAMAGE_BUFF' : 'PHYSICAL_DAMAGE');
            $this->cast($actor, $state, $rankFive, $defense);
            $this->assertSame(8, $actor->getResource($resourceKey));

            $actor->setResource($resourceKey, 12);
            $rankNine = $this->art($jobId, 9, $jobId === 66 ? 'MAGICAL_DAMAGE_BUFF' : 'PHYSICAL_DAMAGE');
            $this->cast($actor, $state, $rankNine, $defense);
            $this->assertSame(0, $actor->getResource($resourceKey));
            $state->jobArtUseCounts[(int) $rankNine->id] = 1;
            $this->assertSame(1, (int) $rankNine->max_uses_per_battle);
            $this->assertSame(1, $state->jobArtUseCounts[(int) $rankNine->id]);
        }
    }

    public function test_guard_semantics_are_current_job_only_and_legacy_master_remains_intact(): void
    {
        $support = app(JobArtBattleSupportService::class);
        $actor = $this->actor('guard', true, 66);
        $skill = $this->art(66, 5, 'MAGICAL_DAMAGE_BUFF');
        $actor->jobArtOrigins[(int) $skill->id] = 'current';

        $execution = $support->skillForExecution($actor, $skill);
        $this->assertSame('MAGICAL_DAMAGE', $execution->effect_template);
        $this->assertSame('magical', $execution->damage_type);
        $this->assertSame('MAGICAL_DAMAGE_BUFF', $skill->effect_template);

        $actor->jobArtOrigins[(int) $skill->id] = 'inherited';
        $inherited = $support->skillForExecution($actor, $skill);
        $this->assertSame('MAGICAL_DAMAGE_BUFF', $inherited->effect_template);

        $actor->jobArtOrigins[(int) $skill->id] = 'current';
        config(['battle.job_art_v2.resources' => false]);
        $legacy = $support->skillForExecution($actor, $skill);
        $this->assertSame('MAGICAL_DAMAGE_BUFF', $legacy->effect_template);
    }

    public function test_guard_display_suppresses_legacy_buff_copy_only_for_current_v2_semantics(): void
    {
        $presenter = app(JobArtV2LoadoutPresenter::class);
        $skill = $this->art(66, 5, 'MAGICAL_DAMAGE_BUFF');

        $current = $presenter->forArt(66, $skill);
        $this->assertSame('MAGICAL_DAMAGE', $current['effect_template']);
        $this->assertTrue($current['legacy_effect_copy_suppressed']);

        $skill->setAttribute('job_art_origin', 'inherited');
        $inherited = $presenter->forArt(66, $skill);
        $this->assertSame('MAGICAL_DAMAGE_BUFF', $inherited['effect_template']);
        $this->assertFalse($inherited['legacy_effect_copy_suppressed']);

        $skill->setAttribute('job_art_origin', 'current');
        config(['battle.job_art_v2.resources' => false]);
        $legacy = $presenter->forArt(66, $skill);
        $this->assertSame('MAGICAL_DAMAGE_BUFF', $legacy['effect_template']);
        $this->assertFalse($legacy['legacy_effect_copy_suppressed']);
    }

    public function test_counter_guard_states_apply_to_equipped_inherited_cards_but_not_flag_off_paths(): void
    {
        $defense = $this->defense(new FixedParryRandomSource([1]));

        foreach ([60, 66] as $jobId) {
            [$actor, , $state] = $this->battle($jobId);
            $skill = $this->art($jobId, 1, $jobId === 66 ? 'MAGICAL_DAMAGE_BUFF' : 'PHYSICAL_DAMAGE');
            $actor->jobArtOrigins[(int) $skill->id] = 'inherited';
            $this->resources()->beginAction($actor, $state);
            $defense->applyJobArtCast($actor, $state, $skill);

            if ($jobId === 60) {
                $this->assertNotNull($actor->counterStanceState());
                $this->assertNull($actor->jobArtV2GuardState());
            } else {
                $this->assertNull($actor->counterStanceState());
                $this->assertNotNull($actor->jobArtV2GuardState());
            }
        }

        config(['battle.job_art_v2.resources' => false]);
        foreach ([60, 66] as $jobId) {
            [$actor, , $state] = $this->battle($jobId);
            $skill = $this->art($jobId, 1, $jobId === 66 ? 'MAGICAL_DAMAGE_BUFF' : 'PHYSICAL_DAMAGE');
            $actor->jobArtOrigins[(int) $skill->id] = 'current';
            $this->resources()->beginAction($actor, $state);
            $defense->applyJobArtCast($actor, $state, $skill);

            $this->assertNull($actor->counterStanceState());
            $this->assertNull($actor->jobArtV2GuardState());
        }
    }

    public function test_loadout_hud_preset_and_six_routes_use_shared_counter_guard_metadata(): void
    {
        $presenter = app(JobArtV2LoadoutPresenter::class);
        $counterOne = $presenter->forArt(60, $this->art(60, 1));
        $guardFive = $presenter->forArt(66, $this->art(66, 5, 'MAGICAL_DAMAGE_BUFF'));
        $guardNine = $presenter->forArt(66, $this->art(66, 9, 'MAGICAL_DAMAGE_BUFF'));

        $this->assertSame('剣勢 +4（使用時）', $counterOne['resource_text']);
        $this->assertStringContainsString('20%で受け流し', implode('|', $counterOne['stance_texts']));
        $this->assertSame([], $counterOne['effect_texts']);
        $this->assertSame('聖護 -4（消費）', $guardFive['resource_text']);
        $this->assertStringContainsString(
            '火傷・毒・出血・防御低下・鈍足・回復阻害・崩し印をすべて浄化する',
            implode('|', $guardFive['effect_texts']),
        );
        $this->assertStringNotContainsString('浄化成功：聖護+1', implode('|', $guardFive['effect_texts']));
        $this->assertSame('MAGICAL_DAMAGE', $guardFive['effect_template']);
        $this->assertSame('攻撃', $guardFive['effect_label']);
        $this->assertTrue($guardFive['legacy_effect_copy_suppressed']);
        $this->assertNotContains(
            '自己強化 主+20% / 副+10%',
            $this->art(66, 5, 'MAGICAL_DAMAGE_BUFF')->jobArtNumericEffectLabels(285, $guardFive['effect_template']),
        );
        $this->assertSame('聖護 -12（消費）', $guardNine['resource_text']);
        $this->assertStringContainsString('45%軽減', implode('|', $guardNine['effect_texts']));

        $counterRecommendations = $presenter->recommendationsForCurrentJob(60, [$this->art(60, 1), $this->art(60, 5), $this->art(60, 9)]);
        $guardRecommendations = $presenter->recommendationsForCurrentJob(66, [$this->art(66, 1), $this->art(66, 5), $this->art(66, 9)]);
        $this->assertStringContainsString('攻撃本体', $counterRecommendations[0]['job_note']);
        $this->assertStringContainsString('浄化と20%加護', $guardRecommendations[1]['job_note']);

        $blade = file_get_contents(resource_path('views/battle/partials/job-art-v2-hud.blade.php'));
        foreach (["'parry'", "'active_guard'", "'cleanse'", '受け流し', '直接ダメージ軽減'] as $needle) {
            $this->assertStringContainsString($needle, $blade);
        }

        foreach ([
            'app/Services/BattleService.php',
            'app/Services/PvPBattleService.php',
            'app/Services/ChampBattleService.php',
            'app/Services/ArenaNpcBattleService.php',
        ] as $path) {
            $source = file_get_contents(base_path($path));
            $this->assertStringContainsString('DirectAttackResolution::fromDamageSource', $source, $path);
        }
        $this->assertStringContainsString(
            '$this->jobArtV2DefenseService->completeDirectAttackAction($attacker, $state);',
            file_get_contents(base_path('app/Services/BattleService.php')),
        );
        $this->assertStringContainsString(
            '$this->jobArtV2DefenseService->completeDirectAttackAction($actor, $state);',
            file_get_contents(base_path('app/Services/JobArtBattleSupportService.php')),
        );
        $this->assertStringContainsString('extends BattleService', file_get_contents(base_path('app/Services/TowerBattleService.php')));
        $this->assertStringContainsString("$"."battleContext = $"."enemy->is_boss ? 'boss' : 'pve'", file_get_contents(base_path('app/Services/BattleService.php')));
        $this->assertStringNotContainsString('job_art_presets', file_get_contents(base_path('app/Services/BattleService.php')));
    }

    private function cast(BattleActor $actor, BattleState $state, Skill $skill, JobArtV2DefenseService $defense): void
    {
        if (! collect($actor->jobArts)->contains(fn (Skill $equipped): bool => (int) $equipped->id === (int) $skill->id)) {
            $actor->jobArts[] = $skill;
        }
        $actor->jobArtOrigins[(int) $skill->id] = 'current';
        $this->resources()->beginAction($actor, $state);
        $this->resources()->applyJobArtCast($actor, $state, $skill);
        $defense->applyJobArtCast($actor, $state, $skill);
        $this->resources()->finishAction($actor, $state);
    }

    private function applyDirect(
        DamageApplicationService $service,
        BattleState $state,
        BattleActor $attacker,
        BattleActor $target,
        int $damage,
        string $category,
        HitResult $hitResult = HitResult::HIT,
        int $hitIndex = 1,
        int $hitCount = 1,
    ): DamageApplicationResult {
        $sourceActionId = $state->currentSourceActionId();
        $this->assertNotNull($sourceActionId);

        return $service->apply(new DamageApplicationRequest(
            sourceActor: $attacker,
            targetActor: $target,
            resolvedDamage: $damage,
            sourceType: DamageSourceType::NORMAL_ATTACK,
            sourceId: null,
            battleType: $state->battleType,
            hitResult: $hitResult,
            hitIndex: $hitIndex,
            hitCount: $hitCount,
            battleState: $state,
            directAttackResolution: new DirectAttackResolution(
                sourceActionId: $sourceActionId,
                attacker: $attacker,
                target: $target,
                hitResult: $hitResult,
                damageCategory: $category,
                direct: true,
                actionType: BattleActionType::NORMAL_ATTACK,
            ),
        ));
    }

    private function defense(
        FixedParryRandomSource $random,
        ?DamageCalculator $damageCalculator = null,
    ): JobArtV2DefenseService
    {
        return new JobArtV2DefenseService(
            app(JobArtV2FeatureGate::class),
            app(JobArtV2PrototypeCatalog::class),
            app(JobArtV2ResourceService::class),
            $random,
            damageCalculator: $damageCalculator,
        );
    }

    private function resources(): JobArtV2ResourceService
    {
        return app(JobArtV2ResourceService::class);
    }

    /** @return array{BattleActor, BattleActor, BattleState} */
    private function battle(int $playerJob, ?int $enemyJob = null, string $battleType = 'pve'): array
    {
        $player = $this->actor('player', true, $playerJob);
        $enemy = $this->actor('enemy', false, $enemyJob);

        return [$player, $enemy, new BattleState($player, $enemy, $battleType)];
    }

    private function actor(string $name, bool $player, ?int $jobId): BattleActor
    {
        $actor = new BattleActor($name, $player, [
            'hp' => 10_000,
            'max_hp' => 10_000,
            'mp' => 1_000,
            'max_mp' => 1_000,
            'str' => 1_000,
            'def' => 500,
            'agi' => 100,
            'mag' => 1_000,
            'spr' => 500,
            'luk' => 100,
            'current_job_id' => $jobId,
        ]);
        if (in_array($jobId, [60, 66], true)) {
            $starter = $this->art((int) $jobId, 1, $jobId === 66 ? 'MAGICAL_DAMAGE_BUFF' : 'PHYSICAL_DAMAGE');
            $actor->jobArts = [$starter];
            $actor->jobArtOrigins[(int) $starter->id] = 'current';
            $actor->jobArtRates[(int) $starter->id] = 1.0;
        }

        return $actor;
    }

    private function art(int $jobId, int $rank, string $template = 'PHYSICAL_DAMAGE'): Skill
    {
        $skill = new Skill([
            'name' => match ([$jobId, $rank]) {
                [60, 1] => '剣冠の構え',
                [60, 5] => '剣冠裁断',
                [60, 9] => '王冠聖剣陣',
                [66, 1] => '聖冠加護',
                [66, 5] => '聖冠大結界',
                [66, 9] => '聖冠アイギスロード',
                default => "job-{$jobId}-rank-{$rank}",
            },
            'skill_type' => 'job_art',
            'job_id' => $jobId,
            'learn_rank' => $rank,
            'effect_template' => $template,
            'damage_type' => str_starts_with($template, 'MAGICAL') ? 'magical' : 'physical',
            'power' => [1 => 225, 5 => 285, 9 => 355][$rank],
            'max_uses_per_battle' => $rank === 9 ? 1 : null,
        ]);
        $skill->setAttribute('id', ($jobId * 100) + $rank);
        $skill->setAttribute('job_art_origin', 'current');

        return $skill;
    }
}

final class FixedParryRandomSource extends JobArtV2ParryRandomSource
{
    public int $calls = 0;

    /** @param list<int> $rolls */
    public function __construct(private array $rolls)
    {
    }

    public function percentRoll(): int
    {
        $this->calls++;

        return array_shift($this->rolls) ?? 100;
    }
}

final class RecordingCounterDamageCalculator extends DamageCalculator
{
    /** @var list<array{route: string, power: int}> */
    public array $calls = [];

    public function calculateDuelDamage(
        BattleActor $attacker,
        BattleActor $defender,
        string $attackType,
        int $skillPower = 100,
        bool $isCritical = false,
        float $affinityMultiplier = 1.0,
        ?int $overrideAtk = null,
        ?int $overrideDef = null,
        ?int $overrideSpr = null,
        ?int $skillPowerCenti = null,
    ): int {
        $this->calls[] = ['route' => 'duel', 'power' => $skillPower];

        return $skillPower;
    }

    public function calculateRankBattleDamage(
        BattleActor $attacker,
        BattleActor $defender,
        string $attackType,
        int $skillPower = 100,
        bool $isCritical = false,
        float $affinityMultiplier = 1.0,
        ?int $overrideAtk = null,
        ?int $overrideDef = null,
        ?int $overrideSpr = null,
        bool $isSkill = false,
        int $hitCount = 1,
        bool $minimumDamageGuaranteeEnabled = true,
        bool $damageCapEnabled = true,
        float $baseDamageMultiplier = 1.0,
        float $additionalDefenseIgnoreRate = 0.0,
        ?int $skillPowerCenti = null,
    ): int {
        $this->calls[] = ['route' => 'rank', 'power' => $skillPower];

        return $skillPower;
    }

    public function calculatePhysicalDamage(
        BattleActor $attacker,
        BattleActor $defender,
        int $skillPower = 100,
        bool $isCritical = false,
        ?int $overrideAtk = null,
        ?int $overrideDef = null,
        ?int $skillPowerCenti = null,
    ): int {
        $this->calls[] = ['route' => 'physical', 'power' => $skillPower];

        return $skillPower;
    }
}
