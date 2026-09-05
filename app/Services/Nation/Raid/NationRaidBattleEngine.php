<?php

namespace App\Services\Nation\Raid;

use Generator;

/**
 * DB・Eloquent・telemetry・共有HP settlementを持たないPhase 1戦闘候補engine。
 *
 * 入力snapshotとseedが同じなら、返すDTOも同じになる。
 */
final class NationRaidBattleEngine
{
    public function __construct(
        private readonly NationRaidRules $rules,
        private readonly NationRaidDamageResolver $damageResolver,
        private readonly NationRaidCounterplayResolver $counterplayResolver,
    ) {}

    public function resolve(NationRaidBattleInput $input): NationRaidBattleResult
    {
        $session = $this->startSession($input);
        while (! $session->finished()) {
            $prompt = $session->beginTurn();
            $session->resolveTurn($input->player->actionForTurn($prompt->turn));
        }

        return $session->result();
    }

    public function startSession(NationRaidBattleInput $input): NationRaidBossTurnSession
    {
        return new NationRaidBossTurnSession($this->turns($input));
    }

    /** @return Generator<int, NationRaidBossTurnPrompt|NationRaidBossTurnResolution, NationRaidBossTurnCommand, NationRaidBattleResult> */
    private function turns(NationRaidBattleInput $input): Generator
    {
        $random = new NationRaidSeededRandom($input->seed);
        $form = $this->rules->formForHp($input->cycleCurrentHp, $input->cycleMaxHp);
        $sp = new NationRaidBossSpState;
        $playerHp = $input->player->maxHp;
        $playerSp = $input->player->maxSp;
        $virtualHp = NationRaidRules::VIRTUAL_MAX_HP;
        $calculatedBossDamage = 0;
        $maxOneActionDamage = 0;
        $turnRecords = [];
        $preparationHistory = [];
        $scheduledPending = null;
        $turnsCompleted = 0;
        $outcome = 'survived';
        $t20StartingSp = -1;
        $denialCandidates = [];
        $ultimateDenialReasons = [];
        $bossDebuffs = [];
        $bossGuardReductionRate = 0.0;
        $bossGuardRemainingActions = 0;

        $runtime = [
            'defense_multiplier' => 1.0,
            'defense_turns' => 0,
            'spirit_multiplier' => 1.0,
            'spirit_turns' => 0,
            'counter_damage_multiplier' => 1.0,
            'counter_damage_turns' => 0,
            'next_direct_multiplier' => 1.0,
            'next_multihit_multiplier' => 1.0,
            'reflect_pending' => false,
            'field_extension_block_turns' => 0,
            'healing_reduction_rate' => 0.0,
            'healing_reduction_turns' => 0,
        ];

        for ($turn = 1; $turn <= NationRaidRules::MAX_TURNS; $turn++) {
            $turnsCompleted = $turn;
            $pending = $scheduledPending !== null && $scheduledPending['scheduled_turn'] === $turn
                ? $scheduledPending
                : null;
            if ($pending !== null) {
                $scheduledPending = null;
            }
            if ($turn === 20 && $pending === null) {
                $pending = $this->createUltimatePending($input);
            }

            $command = yield $this->turnPrompt($turn, $pending, $sp->current(), $virtualHp);
            $playerAction = $command->playerAction;
            $damagePlayer = $input->player;
            if ($command->livePlayerState !== null) {
                $playerHp = $command->livePlayerState->currentHp;
                $playerSp = $command->livePlayerState->currentSp;
                $damagePlayer = $command->livePlayerState->damageSnapshot($input->player);
            }

            $counterplay = null;
            $selectedIdentity = $playerAction->selectedCounterplayIdentity !== null
                && in_array($playerAction->selectedCounterplayIdentity, $input->player->bossSetExactIdentities, true)
                    ? $playerAction->selectedCounterplayIdentity
                    : null;
            if ($pending !== null
                && $pending['kind'] !== 'observation'
                && $input->player->counterplayEnabled
                && $selectedIdentity !== null
            ) {
                $context = new NationRaidCounterplayContext(
                    hit: $playerAction->counterplayHit,
                    canBeGuarded: (bool) $pending['action']['can_be_guarded'],
                    bossSp: $sp->current(),
                    huntingMarkCount: $playerAction->huntingMarkCount,
                    breakMarkCount: $playerAction->breakMarkCount,
                    preparation: $pending['preparation'],
                    alreadyDelayed: $pending['already_delayed'],
                );
                $counterplay = $this->counterplayResolver->resolve($selectedIdentity, $context);
                if ($counterplay->bossSpLoss > 0) {
                    $sp->reduce($turn, $counterplay->bossSpLoss, 'aim_sp_pressure');
                    $denialCandidates[] = 'aim_sp_pressure';
                }
                if ($counterplay->bossRecoverySlowCharges > 0) {
                    $sp->applyRecoverySlow($turn, $counterplay->bossRecoverySlowCharges);
                    $denialCandidates[] = 'transmute_resource_slow';
                }
            } elseif ($pending === null || $pending['kind'] === 'observation' || ! $input->player->counterplayEnabled) {
                $selectedIdentity = null;
            }

            $sources = $this->applyPlayerRuntimeDamageModifiers($playerAction->damageSources, $runtime);
            $playerDamage = $this->damageResolver->resolvePlayerAction(
                sources: $sources,
                turn: $turn,
                form: $form,
                responseDamageMultiplier: $counterplay?->playerDamageMultiplier ?? 1.0,
                additionalBossReduction: $bossGuardReductionRate,
                responseDefenseIgnoreRate: $counterplay?->bossDefenseIgnoreRate ?? 0.0,
            );
            $calculatedBossDamage += $playerDamage['total_damage'];
            $maxOneActionDamage = max($maxOneActionDamage, $playerDamage['max_one_action_damage']);
            $virtualHp = max(0, $virtualHp - $playerDamage['total_damage']);
            if ($bossGuardRemainingActions > 0) {
                $bossGuardRemainingActions--;
                if ($bossGuardRemainingActions === 0) {
                    $bossGuardReductionRate = 0.0;
                }
            }
            foreach ($playerAction->bossDebuffKeysApplied as $debuffKey) {
                $bossDebuffs[$debuffKey] = true;
            }

            $selfDamage = 0;
            if ($runtime['reflect_pending'] && $this->hasDirectDamage($playerDamage['sources'])) {
                $selfDamage = min(
                    max(0, $playerHp - 1),
                    (int) floor($damagePlayer->maxHp * 0.08),
                );
                $playerHp -= $selfDamage;
                $runtime['reflect_pending'] = false;
            }
            $this->completePlayerActionDurations($runtime);

            if ($pending !== null && $counterplay?->delay === true) {
                if ($turn === 20) {
                    $pending['already_delayed'] = true;
                    $ultimateDenialReasons[] = 'turn_20_delay';
                } else {
                    $pending['already_delayed'] = true;
                    $pending['scheduled_turn'] = $turn + 1;
                    $scheduledPending = $pending;
                    $sp->recordNoAction($turn, 'telegraph_delayed_no_action');
                    if ($turn === 18) {
                        $denialCandidates[] = 'turn_18_delay';
                    }
                    $turnRecord = $this->turnRecord(
                        turn: $turn,
                        playerAction: $playerAction,
                        playerDamage: $playerDamage,
                        selectedIdentity: $selectedIdentity,
                        counterplay: $counterplay,
                        pending: $pending,
                        enemyActionId: null,
                        enemyDamage: null,
                        playerSelfDamage: $selfDamage,
                        playerHp: $playerHp,
                        playerSp: $playerSp,
                        bossSp: $sp->current(),
                        note: '予告行動は同じIDのまま次ターンへ遅延した。',
                    );
                    $turnRecords[] = $turnRecord;

                    yield new NationRaidBossTurnResolution(
                        turn: $turn,
                        turnRecord: $turnRecord,
                        playerHp: $playerHp,
                        playerSp: $playerSp,
                        appliedEnemyEffects: [],
                        healingReductionRate: (float) $runtime['healing_reduction_rate'],
                        healingReductionTurns: (int) $runtime['healing_reduction_turns'],
                        finished: false,
                    );

                    continue;
                }
            }

            $enemyActionId = null;
            $enemyAction = null;
            $note = null;
            $isUltimate = false;
            $ultimateReplacement = false;

            if ($turn === 20) {
                $t20StartingSp = $sp->current();
                $delayedAtT20 = $counterplay?->delay === true;
                if (! $delayedAtT20 && $sp->consumeUltimate($turn)) {
                    $enemyActionId = 'ten_lineage_end';
                    $enemyAction = $this->rules->basicAction($enemyActionId);
                    $isUltimate = true;
                } else {
                    if (! $delayedAtT20) {
                        $ultimateDenialReasons = array_merge($ultimateDenialReasons, $denialCandidates, ['insufficient_sp']);
                    }
                    $enemyActionId = $random->nextInt(1, 2) === 1 ? 'black_sky_claw' : 'void_corrosion_orb';
                    $enemyAction = $this->rules->basicAction($enemyActionId);
                    $ultimateReplacement = true;
                    $note = '大技不成立のため同じターンに代替行動を実行した。';
                }
            } elseif ($pending !== null) {
                $enemyActionId = $pending['action_id'];
                $enemyAction = $pending['action'];
                if ($pending['kind'] === 'observation') {
                    $note = $pending['observation_reason'] === 'dominant_lineage_unavailable'
                        ? '最多編成系譜がないため、ヴァルグレイドは十の系譜を見据えている。'
                        : '再臨段階の観測枠で、ヴァルグレイドは次の一手を測っている。';
                }
            } else {
                $enemyActionId = $this->selectBasicActionId($input->stage, $form, $random);
                $enemyAction = $this->rules->basicAction($enemyActionId);
            }

            $preparationSuppressed = $pending !== null
                && $pending['preparation'] instanceof NationRaidTelegraphPreparationState
                && ($counterplay?->suppressUniqueEffect === true || $pending['preparation']->destroyed());

            $enemyDamage = $this->damageResolver->resolveEnemyAction(
                action: $enemyAction,
                stage: $input->stage,
                form: $form,
                turn: $turn,
                player: $damagePlayer,
                random: $random,
                telegraphReductionOverride: $counterplay?->telegraphReductionOverride,
                additionalTelegraphReduction: $counterplay?->additionalTelegraphReduction ?? 0.0,
                suppressUniqueEffect: $counterplay?->suppressUniqueEffect === true || $preparationSuppressed,
                blockAttachedInterference: $counterplay?->blockAttachedInterference === true,
                defenseMultiplier: $runtime['defense_multiplier'],
                spiritMultiplier: $runtime['spirit_multiplier'],
            );
            $incomingApplication = null;
            if ($command->livePlayerState?->incomingDamageApplier !== null && $enemyActionId !== null) {
                $incomingApplication = $command->livePlayerState->incomingDamageApplier->apply(
                    damage: $enemyDamage,
                    enemyActionId: $enemyActionId,
                    playerHpBeforeDamage: $playerHp,
                    playerSpBeforeDamage: $playerSp,
                );
                $enemyDamage = $incomingApplication->damage;
                $playerHp = $incomingApplication->playerHp;
                $playerSp = $incomingApplication->playerSp;
            } else {
                $playerHp = max(0, $playerHp - $enemyDamage->finalDamage);
            }

            if (($incomingApplication?->counterDamage ?? 0) > 0) {
                $counterSources = $this->applyPlayerRuntimeDamageModifiers([[
                    'kind' => NationRaidRules::DAMAGE_COUNTER,
                    'damage' => $incomingApplication->counterDamage,
                    'hit_count' => 1,
                    'defense_ignore_50_damage' => null,
                ]], $runtime);
                $counterDamage = $this->damageResolver->resolvePlayerAction(
                    sources: $counterSources,
                    turn: $turn,
                    form: $form,
                    additionalBossReduction: $bossGuardReductionRate,
                );
                $calculatedBossDamage += $counterDamage['total_damage'];
                $virtualHp = max(0, $virtualHp - $counterDamage['total_damage']);
                $playerDamage['sources'] = [
                    ...$playerDamage['sources'],
                    ...$counterDamage['sources'],
                ];
                $playerDamage['total_damage'] += $counterDamage['total_damage'];
            }

            foreach ($enemyDamage->appliedEffects as $effect) {
                if ($effect === 'cleanse_and_guard_per_debuff') {
                    $removedDebuffCount = count($bossDebuffs);
                    $bossDebuffs = [];
                    $bossGuardReductionRate = min(0.15, $removedDebuffCount * 0.05);
                    $bossGuardRemainingActions = $bossGuardReductionRate > 0 ? 2 : 0;
                }
                $playerSp = $this->applyEnemyEffect($effect, $runtime, $damagePlayer, $playerSp);
            }

            if ($counterplay?->postResolutionDamage > 0) {
                $darkResolution = $this->damageResolver->resolvePlayerAction(
                    sources: [[
                        'kind' => NationRaidRules::DAMAGE_ECLIPSE_BACKLASH,
                        'damage' => $counterplay->postResolutionDamage,
                        'hit_count' => 1,
                        'defense_ignore_50_damage' => null,
                    ]],
                    turn: $turn,
                    form: $form,
                );
                $darkDamage = $darkResolution['total_damage'];
                $calculatedBossDamage += $darkDamage;
                $virtualHp = max(0, $virtualHp - $darkDamage);
                $playerDamage['sources'][] = $darkResolution['sources'][0];
                $playerDamage['total_damage'] += $darkDamage;
            }

            if ($isUltimate) {
                $sp->completedAction($turn);
            } else {
                // 観測・通常・対抗・T20代替はいずれも実行済みのボス行動である。
                $sp->completedAction($turn);
            }

            if ($pending !== null && $pending['preparation'] instanceof NationRaidTelegraphPreparationState) {
                $reason = match (true) {
                    $counterplay?->suppressUniqueEffect === true => 'suppressed',
                    $pending['preparation']->destroyed() => 'executed_after_destroy',
                    $ultimateReplacement => 'replacement',
                    default => 'executed',
                };
                $pending['preparation']->clear($reason);
                $preparationHistory[] = $pending['preparation']->toArray();
            }

            $turnRecord = $this->turnRecord(
                turn: $turn,
                playerAction: $playerAction,
                playerDamage: $playerDamage,
                selectedIdentity: $selectedIdentity,
                counterplay: $counterplay,
                pending: $pending,
                enemyActionId: $enemyActionId,
                enemyDamage: $enemyDamage,
                playerSelfDamage: $selfDamage,
                playerHp: $playerHp,
                playerSp: $playerSp,
                bossSp: $sp->current(),
                note: $note,
            );
            $turnRecords[] = $turnRecord;

            if ($playerHp <= 0) {
                $outcome = 'defeated';
            }

            if ($playerHp > 0 && in_array($turn, [5, 11, 17], true)) {
                if ($sp->reserve($turn)) {
                    $scheduledPending = $this->createReservedPending($input, $turn + 1);
                }
            }

            $finished = $playerHp <= 0 || $turn >= NationRaidRules::MAX_TURNS;
            yield new NationRaidBossTurnResolution(
                turn: $turn,
                turnRecord: $turnRecord,
                playerHp: $playerHp,
                playerSp: $playerSp,
                appliedEnemyEffects: $enemyDamage->appliedEffects,
                healingReductionRate: (float) $runtime['healing_reduction_rate'],
                healingReductionTurns: (int) $runtime['healing_reduction_turns'],
                finished: $finished,
            );

            if ($finished) {
                break;
            }
        }

        if ($scheduledPending !== null && $scheduledPending['preparation'] instanceof NationRaidTelegraphPreparationState) {
            $scheduledPending['preparation']->clear('battle_end');
            $preparationHistory[] = $scheduledPending['preparation']->toArray();
        }

        return new NationRaidBattleResult(
            battleType: NationRaidRules::BATTLE_TYPE,
            stage: $input->stage,
            form: $form,
            bossSpeciesKey: NationRaidRules::BOSS_SPECIES_KEY,
            seed: $input->seed,
            rulesetHash: $this->rules->rulesetHash(),
            strategy: $input->strategy,
            bossSetExactIdentities: $input->player->bossSetExactIdentities,
            turnsCompleted: $turnsCompleted,
            outcome: $outcome,
            playerRemainingHp: $playerHp,
            bossVirtualRemainingHp: $virtualHp,
            calculatedBossDamage: $calculatedBossDamage,
            maxOneActionDamage: $maxOneActionDamage,
            t20StartingSp: $t20StartingSp,
            turns: $turnRecords,
            spTrace: $sp->trace(),
            ultimateDenialReasons: array_values(array_unique($ultimateDenialReasons)),
            reservationFailureCount: $sp->reservationFailureCount(),
            preparationHistory: $preparationHistory,
        );
    }

    /** @param array<string, mixed>|null $pending */
    private function turnPrompt(int $turn, ?array $pending, int $bossSp, int $bossVirtualHp): NationRaidBossTurnPrompt
    {
        $preparation = $pending['preparation'] ?? null;

        return new NationRaidBossTurnPrompt(
            turn: $turn,
            pendingEnemyActionKey: isset($pending['pending_enemy_action_id'])
                ? (string) $pending['pending_enemy_action_id']
                : null,
            pendingKind: isset($pending['kind']) ? (string) $pending['kind'] : null,
            canBeGuarded: (bool) ($pending['action']['can_be_guarded'] ?? false),
            preparationDestroyable: $preparation instanceof NationRaidTelegraphPreparationState
                && $preparation->isActive(),
            bossSpAvailable: $bossSp > 0,
            bossResourceSlowAvailable: true,
            bossVirtualHp: max(0, min(NationRaidRules::VIRTUAL_MAX_HP, $bossVirtualHp)),
        );
    }

    /** @return array<string, mixed> */
    private function createReservedPending(NationRaidBattleInput $input, int $scheduledTurn): array
    {
        $kind = $this->rules->reservedSlotKind($input->stage, $scheduledTurn, $input->dominantLineage);
        $pendingId = $input->sourceCycleId.':pending:'.$scheduledTurn;
        $preparation = null;

        if ($kind === 'observation') {
            $actionId = 'lineage_observation';
            $action = [
                'name' => '系譜観測',
                'hits' => [],
                'effect' => null,
                'can_be_guarded' => false,
            ];
            $stageSlot = $this->rules->stageParameters($input->stage)['reserved_slots'][$scheduledTurn];
            $observationReason = $stageSlot === 'observation'
                ? 'stage_not_unlocked'
                : 'dominant_lineage_unavailable';
        } else {
            $counterAction = $this->rules->counterAction($input->dominantLineage);
            $actionId = $counterAction['action_id'];
            $action = $counterAction;
            $observationReason = null;
            if ($counterAction['preparation_kind'] !== null) {
                $preparation = new NationRaidTelegraphPreparationState(
                    preparationId: $pendingId.':preparation',
                    pendingEnemyActionId: $pendingId,
                    kind: $counterAction['preparation_kind'],
                    sourceCycleId: $input->sourceCycleId,
                    createdTurn: $scheduledTurn - 1,
                    expiresOn: min(NationRaidRules::MAX_TURNS, $scheduledTurn + 1),
                );
            }
        }

        return [
            'pending_enemy_action_id' => $pendingId,
            'kind' => $kind,
            'action_id' => $actionId,
            'action' => $action,
            'scheduled_turn' => $scheduledTurn,
            'already_delayed' => false,
            'preparation' => $preparation,
            'observation_reason' => $observationReason,
        ];
    }

    /** @return array<string, mixed> */
    private function createUltimatePending(NationRaidBattleInput $input): array
    {
        return [
            'pending_enemy_action_id' => $input->sourceCycleId.':pending:20',
            'kind' => 'ultimate',
            'action_id' => 'ten_lineage_end',
            'action' => $this->rules->basicAction('ten_lineage_end'),
            'scheduled_turn' => 20,
            'already_delayed' => false,
            'preparation' => null,
            'observation_reason' => null,
        ];
    }

    private function selectBasicActionId(
        int $stage,
        string $form,
        NationRaidRandomSource $random,
    ): string {
        $weights = $this->rules->actionWeights($stage, $form);
        $roll = $random->nextInt(1, 100);
        if ($roll <= $weights['basic_physical']) {
            return 'black_sky_claw';
        }
        if ($roll <= $weights['basic_physical'] + $weights['basic_magical']) {
            return 'void_corrosion_orb';
        }

        return $this->rules->formParameters($form)['form_action'];
    }

    /**
     * @param  list<array{kind:string,damage:int,hit_count:int,defense_ignore_50_damage:?int}>  $sources
     * @param  array<string, int|float|bool>  $runtime
     * @return list<array{kind:string,damage:int,hit_count:int,defense_ignore_50_damage:?int}>
     */
    private function applyPlayerRuntimeDamageModifiers(array $sources, array &$runtime): array
    {
        $directConsumed = false;
        $multihitConsumed = false;

        foreach ($sources as &$source) {
            $multiplier = 1.0;
            if ($source['kind'] === NationRaidRules::DAMAGE_COUNTER && $runtime['counter_damage_turns'] > 0) {
                $multiplier *= $runtime['counter_damage_multiplier'];
            }
            if (! $directConsumed && $source['kind'] === NationRaidRules::DAMAGE_DIRECT
                && $runtime['next_direct_multiplier'] < 1.0) {
                $multiplier *= $runtime['next_direct_multiplier'];
                $runtime['next_direct_multiplier'] = 1.0;
                $directConsumed = true;
            }
            if (! $multihitConsumed && $source['hit_count'] > 1 && $runtime['next_multihit_multiplier'] < 1.0) {
                $multiplier *= $runtime['next_multihit_multiplier'];
                $runtime['next_multihit_multiplier'] = 1.0;
                $multihitConsumed = true;
            }
            if ($multiplier < 1.0) {
                $source['damage'] = (int) floor($source['damage'] * $multiplier);
                if ($source['defense_ignore_50_damage'] !== null) {
                    $source['defense_ignore_50_damage'] = (int) floor($source['defense_ignore_50_damage'] * $multiplier);
                }
            }
        }
        unset($source);

        return $sources;
    }

    /** @param array<string, int|float|bool> $runtime */
    private function completePlayerActionDurations(array &$runtime): void
    {
        foreach (['defense', 'spirit', 'counter_damage', 'field_extension_block', 'healing_reduction'] as $key) {
            $turnKey = $key.'_turns';
            if (($runtime[$turnKey] ?? 0) > 0) {
                $runtime[$turnKey]--;
                if ($runtime[$turnKey] === 0) {
                    if (isset($runtime[$key.'_multiplier'])) {
                        $runtime[$key.'_multiplier'] = 1.0;
                    }
                    if ($key === 'healing_reduction') {
                        $runtime['healing_reduction_rate'] = 0.0;
                    }
                }
            }
        }
    }

    /** @param array<string, int|float|bool> $runtime */
    private function applyEnemyEffect(
        string $effect,
        array &$runtime,
        NationRaidPlayerSnapshot $player,
        int $currentPlayerSp,
    ): int {
        switch ($effect) {
            case 'defense_down_10_two_actions':
                $runtime['defense_multiplier'] = min($runtime['defense_multiplier'], 0.90);
                $runtime['defense_turns'] = 2;
                break;
            case 'healing_down_25_two_actions':
                $runtime['healing_reduction_rate'] = max($runtime['healing_reduction_rate'], 0.25);
                $runtime['healing_reduction_turns'] = 2;
                break;
            case 'counter_damage_down_50':
                $runtime['counter_damage_multiplier'] = 0.50;
                $runtime['counter_damage_turns'] = 1;
                break;
            case 'field_remove_and_extension_block':
                $runtime['field_extension_block_turns'] = 1;
                break;
            case 'current_sp_down_8':
                $currentPlayerSp = max(0, $currentPlayerSp - (int) floor($player->maxSp * 0.08));
                break;
            case 'nonlethal_reflect_max_hp_8':
                $runtime['reflect_pending'] = true;
                break;
            case 'defense_spirit_healing_down_25_two_actions':
                $runtime['defense_multiplier'] = min($runtime['defense_multiplier'], 0.75);
                $runtime['spirit_multiplier'] = min($runtime['spirit_multiplier'], 0.75);
                $runtime['defense_turns'] = 2;
                $runtime['spirit_turns'] = 2;
                $runtime['healing_reduction_rate'] = max($runtime['healing_reduction_rate'], 0.25);
                $runtime['healing_reduction_turns'] = 2;
                break;
            case 'hp_sp_healing_down_50_two_actions':
                $runtime['healing_reduction_rate'] = max($runtime['healing_reduction_rate'], 0.50);
                $runtime['healing_reduction_turns'] = 2;
                break;
            case 'drain_healing_down_50_one_action':
                $runtime['healing_reduction_rate'] = max($runtime['healing_reduction_rate'], 0.50);
                $runtime['healing_reduction_turns'] = 1;
                break;
            case 'next_direct_damage_down_30':
                $runtime['next_direct_multiplier'] = min($runtime['next_direct_multiplier'], 0.70);
                break;
            case 'clear_marks_and_next_multihit_down_25':
                $runtime['next_multihit_multiplier'] = min($runtime['next_multihit_multiplier'], 0.75);
                break;
        }

        return $currentPlayerSp;
    }

    /** @param list<array<string, mixed>> $sources */
    private function hasDirectDamage(array $sources): bool
    {
        foreach ($sources as $source) {
            if ($source['kind'] === NationRaidRules::DAMAGE_DIRECT && $source['applied_damage'] > 0) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function turnRecord(
        int $turn,
        NationRaidPlayerActionSnapshot $playerAction,
        array $playerDamage,
        ?string $selectedIdentity,
        ?NationRaidCounterplayResolution $counterplay,
        ?array $pending,
        ?string $enemyActionId,
        ?NationRaidEnemyDamageResult $enemyDamage,
        int $playerSelfDamage,
        int $playerHp,
        int $playerSp,
        int $bossSp,
        ?string $note,
    ): array {
        return [
            'turn' => $turn,
            'player_action_snapshot_turn' => $playerAction->turn,
            'player_damage' => $playerDamage,
            'selected_counterplay_identity' => $selectedIdentity,
            'counterplay' => $counterplay?->toArray(),
            'pending_enemy_action_id' => $pending['pending_enemy_action_id'] ?? null,
            'pending_kind' => $pending['kind'] ?? null,
            'observation_reason' => $pending['observation_reason'] ?? null,
            'enemy_action_id' => $enemyActionId,
            'enemy_damage' => $enemyDamage?->toArray(),
            'player_self_damage' => $playerSelfDamage,
            'player_hp_after' => $playerHp,
            'player_sp_after' => $playerSp,
            'boss_sp_after' => $bossSp,
            'note' => $note,
        ];
    }
}
