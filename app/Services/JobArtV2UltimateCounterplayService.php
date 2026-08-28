<?php

namespace App\Services;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DirectAttackResolution;
use App\Services\Battle\HitResult;

/** PvP系の奥義予告と、PvE大技予告への応答を扱うbattle-memory-only状態。 */
final class JobArtV2UltimateCounterplayService
{
    public const BLOCKED_PREPARING = 'blocked_by_ultimate_preparing';
    public const BLOCKED_DELAYED = 'blocked_by_ultimate_readiness_delay';

    public function __construct(
        private readonly JobArtV2FeatureGate $featureGate,
        private readonly JobArtV2DeckRoleResolver $deckRoleResolver,
        private readonly JobArtV2ResourceCatalog $resourceCatalog,
        private readonly JobArtV2ProgressionService $progressionService,
        private readonly JobArtV2UltimateCounterplayCatalog $catalog,
    ) {}

    public function enabledFor(BattleState $state): bool
    {
        return $this->featureGate->usesUltimateCounterplay($state);
    }

    public function opponentIsPreparing(BattleActor $actor, BattleState $state): bool
    {
        if ($this->pveTelegraphAvailable($actor, $state)) {
            return true;
        }
        if (! $this->enabledFor($state)) {
            return false;
        }

        return $this->opponent($actor, $state)
            ->existingJobArtV2UltimateCounterplayState()
            ?->preparation
            ?->isPreparing() === true;
    }

    public function isResponseCandidate(BattleActor $actor, BattleState $state, Skill $skill): bool
    {
        if ((! $this->enabledFor($state) && ! $this->pveTelegraphAvailable($actor, $state))
            || ! $this->catalog->isCounterplayArt($skill)
            || ! $this->isFormalArt($actor, $skill)
            || ! $this->opponentIsPreparing($actor, $state)
        ) {
            return false;
        }

        if ($this->pveTelegraphAvailable($actor, $state)) {
            return match ($this->catalog->effectFor($skill)) {
                JobArtV2UltimateCounterplayCatalog::COUNTER_INTERCEPT,
                JobArtV2UltimateCounterplayCatalog::ULTIMATE_GUARD =>
                    (bool) ($state->enemyTelegraphContext['can_be_guarded'] ?? false),
                JobArtV2UltimateCounterplayCatalog::HUNT_CANCEL =>
                    $this->progressionService->huntingMarkCountFor($state->enemy, $actor) > 0,
                JobArtV2UltimateCounterplayCatalog::BREAK_PREPARATION =>
                    $this->progressionService->breakMarkCountFor($state->enemy, $actor) > 0
                    && $this->hasDestroyablePvePreparation($state->enemy),
                // 代替効果が未裁定のため、SP/系譜資源を持たない敵には
                // 反応候補として選ばせず、行動だけを空費させない。
                JobArtV2UltimateCounterplayCatalog::AIM_SP_PRESSURE => $state->enemy->maxMp > 0,
                JobArtV2UltimateCounterplayCatalog::TRANSMUTE_RESOURCE_SLOW =>
                    $this->resourceCatalog->forActor($state->enemy) !== null,
                default => true,
            };
        }

        $target = $this->opponent($actor, $state);
        $targetCounterplay = $target->existingJobArtV2UltimateCounterplayState();
        $targetMainLineage = (string) ($targetCounterplay?->preparation?->mainLineage ?? '');

        return match ($this->catalog->effectFor($skill)) {
            JobArtV2UltimateCounterplayCatalog::HUNT_CANCEL =>
                ! ($targetCounterplay?->huntCancelResistance ?? false)
                && $this->progressionService->huntingMarkCountFor($target, $actor) > 0,
            JobArtV2UltimateCounterplayCatalog::BREAK_PREPARATION =>
                $this->progressionService->breakMarkCountFor($target, $actor) > 0
                && $this->hasDestroyableUltimatePreparation($target, $targetMainLineage),
            default => true,
        };
    }

    public function isReadyMainRankNine(BattleActor $actor, BattleState $state, Skill $skill): bool
    {
        if (! $this->enabledFor($state) || ! $this->isMainRankNine($actor, $skill)) {
            return false;
        }

        $preparation = $actor->existingJobArtV2UltimateCounterplayState()?->preparation;

        return $preparation?->isReady() === true
            && $preparation->delayOwnActionsRemaining < 1;
    }

    public function eligibilityBlockReason(BattleActor $actor, BattleState $state, Skill $skill): ?string
    {
        if (! $this->enabledFor($state) || ! $this->isMainRankNine($actor, $skill)) {
            return null;
        }

        $resource = $this->resourceCatalog->forActorArt($actor, $skill);
        if ($resource === null
            || $actor->getResource((string) $resource['resource_key']) < (int) $resource['resource_max_points']
        ) {
            return null;
        }

        $preparation = $actor->existingJobArtV2UltimateCounterplayState()?->preparation;
        if ($preparation === null) {
            return self::BLOCKED_PREPARING;
        }
        if ($preparation->isPreparing()) {
            return self::BLOCKED_PREPARING;
        }
        if ($preparation->delayOwnActionsRemaining > 0) {
            return self::BLOCKED_DELAYED;
        }

        return null;
    }

    /**
     * Activation成功後・実行確定時に呼ぶ。HIT判定前なので、連携成立と
     * 奥義専用防御はMISS/EVADEでも同じactionに対して確定する。
     */
    public function beginJobArtCast(BattleActor $actor, BattleState $state, Skill $skill): void
    {
        $sourceActionId = $state->currentSourceActionId();
        $pveTelegraph = $this->pveTelegraphAvailable($actor, $state);
        if ((! $this->enabledFor($state) && ! $pveTelegraph)
            || $sourceActionId === null
            || ! $this->isFormalArt($actor, $skill)
        ) {
            return;
        }

        if ($pveTelegraph) {
            $this->beginPveTelegraphResponse($actor, $state, $skill, $sourceActionId);

            return;
        }

        $actorState = $actor->jobArtV2UltimateCounterplayState();
        if ($this->isMainRankFive($actor, $skill)) {
            $actorState->mainRankFiveEstablished = true;
            $actorState->establishedUltimateLineage = (string) (
                $this->resourceCatalog->forActorArt($actor, $skill)['lineage_key'] ?? ''
            );
        }

        if ($this->isMainRankNine($actor, $skill)) {
            $preparation = $actorState->preparation;
            if ($preparation?->isReady() === true && $preparation->delayOwnActionsRemaining < 1) {
                $lineageSuppression = $actorState->lineageSuppression;
                $suppressed = $lineageSuppression !== null
                    && $lineageSuppression->targetCycleId === $preparation->cycleId;
                $state->updateJobArtV2RoleAction($sourceActionId, [
                    'ultimate_counterplay_main_rank_nine' => true,
                    'ultimate_counterplay_cycle_id' => $preparation->cycleId,
                    'ultimate_counterplay_owner_key' => $state->actorKey($actor),
                    'ultimate_counterplay_lineage_suppressed' => $suppressed,
                ]);
                if ($suppressed) {
                    $actorState->lineageSuppression = null;
                    $state->addLog('<span class="text-sky-700 font-bold">静寂の場により '.e($actor->name).' の奥義は基礎効果だけで発動する。</span>');
                }
                $actorState->preparation = null;
                $actorState->mainRankFiveEstablished = false;
                $actorState->establishedUltimateLineage = null;
            }
        }

        $effect = $this->catalog->effectFor($skill);
        $target = $this->opponent($actor, $state);
        $targetPreparation = $target->existingJobArtV2UltimateCounterplayState()?->preparation;
        if ($effect === null || $targetPreparation?->isPreparing() !== true) {
            return;
        }

        $state->updateJobArtV2RoleAction($sourceActionId, [
            'ultimate_counterplay_response_effect' => $effect,
            'ultimate_counterplay_target_key' => $state->actorKey($target),
            'ultimate_counterplay_target_cycle_id' => $targetPreparation->cycleId,
        ]);

        if ($effect === JobArtV2UltimateCounterplayCatalog::HUNT_CANCEL) {
            $state->updateJobArtV2RoleAction($sourceActionId, [
                'ultimate_counterplay_hunt_mark_at_start' => $this->progressionService
                    ->huntingMarkCountFor($target, $actor) > 0,
            ]);

            return;
        }

        if ($effect === JobArtV2UltimateCounterplayCatalog::FIELD_SUPPRESSION) {
            $target->jobArtV2UltimateCounterplayState()->lineageSuppression = new JobArtV2UltimateCycleEffectState(
                sourceActorKey: $state->actorKey($actor),
                targetCycleId: $targetPreparation->cycleId,
                effect: $effect,
            );
            $state->addLog('<span class="text-sky-700 font-bold">'.e($actor->name).' は静寂の場を展開し、予告された奥義の系譜効果を抑えた！</span>');

            return;
        }

        if ($effect === JobArtV2UltimateCounterplayCatalog::COUNTER_INTERCEPT) {
            $actor->replaceJobArtV2GuardState(null);
            $actorState->ultimateGuard = new JobArtV2UltimateGuardState(
                targetActorKey: $state->actorKey($target),
                targetCycleId: $targetPreparation->cycleId,
                rate: 0.20,
                effect: $effect,
                responseSkillId: (int) $skill->id,
            );
            $state->addLog('<span class="text-cyan-700 font-bold">'.e($actor->name).' は無拍子の迎撃を構えた！（20%軽減）</span>');

            return;
        }

        if ($effect === JobArtV2UltimateCounterplayCatalog::ULTIMATE_GUARD) {
            // 通常の20%軽減との二重適用を避け、予告された奥義専用へ置換する。
            $actor->replaceJobArtV2GuardState(null);
            $actorState->ultimateGuard = new JobArtV2UltimateGuardState(
                targetActorKey: $state->actorKey($target),
                targetCycleId: $targetPreparation->cycleId,
                responseSkillId: (int) $skill->id,
            );
            $state->addLog('<span class="text-blue-700 font-bold">'.e($actor->name).' は予告された奥義を受け切る構えを取った！（35%軽減）</span>');

            return;
        }

        if ($effect === JobArtV2UltimateCounterplayCatalog::READINESS_DELAY
            && $targetPreparation->applyOneActionDelay()
        ) {
            $state->addLog('<span class="text-violet-700 font-bold">'.e($actor->name).' は号令で '.e($target->name).' の奥義発動を1行動遅らせた！</span>');
        }
    }

    public function completeJobArtCast(
        BattleActor $actor,
        BattleActor $target,
        BattleState $state,
        Skill $skill,
        ?HitResult $hitResult,
    ): void {
        $sourceActionId = $state->currentSourceActionId();
        if ($sourceActionId === null) {
            return;
        }

        $context = $state->jobArtV2RoleAction($sourceActionId);
        $effect = $context['ultimate_counterplay_response_effect'] ?? null;
        if (! is_string($effect) || $hitResult?->landed() !== true) {
            return;
        }

        if (($context['ultimate_counterplay_pve_telegraph'] ?? false) === true) {
            $this->completePveTelegraphResponse($actor, $target, $state, $effect, $hitResult);

            return;
        }
        if (! $this->enabledFor($state)) {
            return;
        }

        $targetState = $target->jobArtV2UltimateCounterplayState();
        $preparation = $targetState->preparation;
        if ($preparation?->isPreparing() !== true
            || $preparation->cycleId !== (int) ($context['ultimate_counterplay_target_cycle_id'] ?? 0)
        ) {
            return;
        }

        $cycleId = $preparation->cycleId;

        if ($effect === JobArtV2UltimateCounterplayCatalog::HUNT_CANCEL
            && ! empty($context['ultimate_counterplay_hunt_mark_at_start'])
        ) {
            $targetState->preparation = null;
            $targetState->mainRankFiveEstablished = false;
            $targetState->establishedUltimateLineage = null;
            $targetState->huntCancelResistance = true;
            $this->clearCycleEffects($state, $target, $cycleId);
            $state->updateJobArtV2RoleAction($sourceActionId, [
                'ultimate_counterplay_hunt_cancelled' => true,
            ]);
            $state->addLog('<span class="text-slate-800 font-extrabold">'.e($actor->name).' の影縫いが '.e($target->name).' の奥義準備を中断した！ 資源は残った。</span>');

            return;
        }

        if ($effect === JobArtV2UltimateCounterplayCatalog::ECLIPSE_BACKLASH) {
            $targetState->eclipseBacklash = new JobArtV2UltimateCycleEffectState(
                sourceActorKey: $state->actorKey($actor),
                targetCycleId: $cycleId,
                effect: $effect,
                rate: 0.05,
            );
            $state->addLog('<span class="text-fuchsia-800 font-bold">'.e($target->name).' に冥蝕反噬が刻まれた！</span>');

            return;
        }

        if ($effect === JobArtV2UltimateCounterplayCatalog::AIM_SP_PRESSURE) {
            $lost = min($target->mp, max(1, (int) floor($target->maxMp * 0.03)));
            $target->mp = max(0, $target->mp - $lost);
            $state->addLog('<span class="text-cyan-800 font-bold">'.e($actor->name).' は奥義の要を狙い、'.e($target->name).' のSPを '.$lost.' 減らした！</span>');

            return;
        }

        if ($effect === JobArtV2UltimateCounterplayCatalog::TRANSMUTE_RESOURCE_SLOW) {
            $targetState->pendingResourceSlow = new JobArtV2UltimateCycleEffectState(
                sourceActorKey: $state->actorKey($actor),
                targetCycleId: $cycleId,
                effect: $effect,
            );
            $state->addLog('<span class="text-amber-800 font-bold">'.e($actor->name).' は '.e($target->name).' の次の資源循環へ鈍化を仕込んだ！</span>');

            return;
        }

        if ($effect === JobArtV2UltimateCounterplayCatalog::BREAK_PREPARATION
            && $this->progressionService->consumeBreakMarksFor($target, $actor, 1)
        ) {
            $destroyed = $this->destroyUltimatePreparation($target, $preparation->mainLineage);
            if ($destroyed !== null) {
                $state->addLog('<span class="text-orange-800 font-bold">'.e($actor->name).' の羅刹連撃が '.e($target->name).' の「'.e($destroyed).'」を破壊した！</span>');
            }
        }
    }

    /** action終了後に応答窓・遅延・新しい予告を一度だけ進める。 */
    public function finishAction(BattleActor $actor, BattleState $state): void
    {
        $sourceActionId = $state->currentSourceActionId();
        if (! $this->enabledFor($state)
            || $sourceActionId === null
            || ! $state->claimJobArtV2RoleEffect($actor, 'ultimate_counterplay_finish', $sourceActionId)
        ) {
            return;
        }

        $context = $state->jobArtV2RoleAction($sourceActionId);
        $actorState = $actor->jobArtV2UltimateCounterplayState();
        if (! empty($context['ultimate_counterplay_main_rank_nine'])) {
            $cycleId = (int) ($context['ultimate_counterplay_cycle_id'] ?? 0);
            $backlash = $actorState->eclipseBacklash;
            if ($backlash !== null && $backlash->targetCycleId === $cycleId) {
                $damage = $actor->hp > 0
                    ? min(
                        max(0, $actor->hp - 1),
                        max(1, (int) floor($actor->maxHp * $backlash->rate)),
                    )
                    : 0;
                if ($damage > 0) {
                    $actor->hp = max(1, $actor->hp - $damage);
                    $state->addLog('<span class="text-fuchsia-900 font-extrabold">冥蝕反噬が '.e($actor->name).' を蝕み、HPを '.$damage.' 失わせた！（非致死）</span>');
                }
                $actorState->eclipseBacklash = null;
            }
            $pendingSlow = $actorState->pendingResourceSlow;
            if ($pendingSlow !== null && $pendingSlow->targetCycleId === $cycleId) {
                $actorState->pendingResourceSlow = null;
                $actorState->resourceGainPenaltyCharges = 2;
                $state->addLog('<span class="text-amber-800 font-bold">'.e($actor->name).' の次の2回の系譜資源獲得量が1ずつ減る。</span>');
            }
            $this->clearGuardsForCycle($state, $state->actorKey($actor), $cycleId);
        } else {
            // 反噬と静寂は、付与された側が奥義以外の次行動を終えると消える。
            $actorState->eclipseBacklash = null;
            $actorState->lineageSuppression = null;
        }

        $hadHuntResistance = $actorState->huntCancelResistance;
        $this->validatePreparationResource($actor, $state);

        $preparation = $actorState->preparation;
        if ($preparation?->isReady() === true && $preparation->delayOwnActionsRemaining > 0) {
            $preparation->delayOwnActionsRemaining--;
            if ($preparation->delayOwnActionsRemaining === 0) {
                $state->addLog('<span class="text-amber-700 font-bold">'.e($actor->name).' の奥義遅延が解け、発動可能になった！</span>');
            }
        }

        // このactionが、相手の予告に対する唯一の応答機会だった。
        $target = $this->opponent($actor, $state);
        $targetPreparation = $target->existingJobArtV2UltimateCounterplayState()?->preparation;
        if ($targetPreparation?->isPreparing() === true) {
            $targetPreparation->markReady();
            $state->addLog('<span class="text-amber-700 font-extrabold">'.e($target->name).' の奥義が発動可能になった！</span>');
        }

        // 封狩への耐性は、キャンセルされた側の次のown action終了まで。
        // キャンセル耐性が解けた同じ終了処理で、資源が足りていれば再予告へ入れる。
        if ($hadHuntResistance) {
            $actorState->huntCancelResistance = false;
        }

        $this->tryEnterPreparation($actor, $state);
        $this->tryEnterPreparation($target, $state);
    }

    public function ultimateGuardForIncoming(
        BattleActor $target,
        BattleState $state,
        DirectAttackResolution $resolution,
    ): ?JobArtV2UltimateGuardState {
        if (($state->enemyTelegraphContext['executing'] ?? false) === true
            && $resolution->attacker === $state->enemy
        ) {
            $guard = $target->existingJobArtV2UltimateCounterplayState()?->ultimateGuard;
            if ($guard === null
                || $guard->targetActorKey !== $state->actorKey($resolution->attacker)
                || $guard->targetCycleId !== (int) ($state->enemyTelegraphContext['cycle_id'] ?? 0)
            ) {
                return null;
            }

            return $guard;
        }
        if (! $this->enabledFor($state)) {
            return null;
        }

        $guard = $target->existingJobArtV2UltimateCounterplayState()?->ultimateGuard;
        $context = $state->jobArtV2RoleAction($resolution->sourceActionId);
        if ($guard === null
            || empty($context['ultimate_counterplay_main_rank_nine'])
            || $guard->targetActorKey !== $state->actorKey($resolution->attacker)
            || $guard->targetCycleId !== (int) ($context['ultimate_counterplay_cycle_id'] ?? 0)
        ) {
            return null;
        }

        return $guard;
    }

    public function consumeUltimateGuard(BattleActor $actor, int $sourceActionId): void
    {
        $actorState = $actor->jobArtV2UltimateCounterplayState();
        if ($actorState->ultimateGuard !== null) {
            $actorState->consumedUltimateGuards[$sourceActionId] = $actorState->ultimateGuard;
            $actorState->ultimateGuard = null;
        }
    }

    public function recordUltimateMitigation(
        BattleActor $actor,
        BattleState $state,
        DirectAttackResolution $resolution,
        int $preventedDamage,
    ): void {
        if (! $this->enabledFor($state)
            && ($state->enemyTelegraphContext['executing'] ?? false) !== true
        ) {
            return;
        }

        $actorState = $actor->existingJobArtV2UltimateCounterplayState();
        if ($actorState === null) {
            return;
        }
        $guard = $actorState->consumedUltimateGuards[$resolution->sourceActionId] ?? null;
        if ($guard === null
            || $guard->rewardGranted
            || $preventedDamage < 1
            || $guard->effect !== JobArtV2UltimateCounterplayCatalog::COUNTER_INTERCEPT
        ) {
            return;
        }

        $guard->rewardGranted = true;
        $existing = $actor->jobArtV2PreparedEffect('counter_focus');
        if ($existing === null || $existing->multiplier < 1.20) {
            $actor->replaceJobArtV2PreparedEffect(new JobArtV2PreparedEffectState(
                key: 'counter_focus',
                multiplier: 1.20,
                appliedRound: $state->turnCount,
                remainingRounds: null,
                charges: 1,
                sourceActionId: $resolution->sourceActionId,
                sourceSkillId: $guard->responseSkillId,
                targetLineage: 'counter',
                targetRanks: [5, 9],
                strictNextAction: false,
                group: 'counter_focus',
                remainingActionOpportunities: null,
            ));
        }
        $state->addLog('<span class="text-cyan-800 font-extrabold">'.e($actor->name).' は奥義を受け流し、次の反撃を1.20倍へ高めた！</span>');
    }

    public function lineageEffectsSuppressedForCurrentAction(
        BattleActor $actor,
        BattleState $state,
        Skill $skill,
    ): bool {
        if (! $this->enabledFor($state) || ! $this->isMainRankNine($actor, $skill)) {
            return false;
        }

        return $this->currentActionLineageEffectsSuppressed($actor, $state);
    }

    /** 現在actionの後段（場補正など）から、奥義masterを受け取らずに参照する中央gate。 */
    public function currentActionLineageEffectsSuppressed(BattleActor $actor, BattleState $state): bool
    {
        if (! $this->enabledFor($state)) {
            return false;
        }

        $context = $state->jobArtV2RoleAction();

        return ($context['ultimate_counterplay_owner_key'] ?? null) === $state->actorKey($actor)
            && ! empty($context['ultimate_counterplay_lineage_suppressed']);
    }

    public function baseOnlySkillForExecution(
        BattleActor $actor,
        BattleState $state,
        Skill $skill,
    ): ?Skill {
        if (! $this->lineageEffectsSuppressedForCurrentAction($actor, $state, $skill)) {
            return null;
        }

        $executionSkill = clone $skill;
        // 静寂の場は source master の基礎効果をそのまま実行する。
        // 補助奥義など power=0 の技を、既定値100へ変換してはいけない。
        $power = max(0, (int) $skill->power);
        $executionSkill->power = $power;
        $executionSkill->power_multiplier = $power / 100;

        return $executionSkill;
    }

    public function applyForExecution(
        BattleActor $actor,
        BattleActor $target,
        BattleState $state,
        Skill $sourceSkill,
        Skill $executionSkill,
    ): void {
        $context = $state->jobArtV2RoleAction();
        if (($context['ultimate_counterplay_response_effect'] ?? null)
                !== JobArtV2UltimateCounterplayCatalog::PIERCE_OPENING
            || ($context['ultimate_counterplay_target_key'] ?? null) !== $state->actorKey($target)
        ) {
            return;
        }

        $currentMultiplier = max(0.0, (float) ($executionSkill->getAttribute('job_art_v2_target_damage_multiplier') ?? 1.0));
        $executionSkill->setAttribute('job_art_v2_target_damage_multiplier', $currentMultiplier * 1.15);
        $executionSkill->setAttribute('job_art_v2_defense_stat', 'def');
        $executionSkill->setAttribute('job_art_v2_defense_ignore_percent', 50);
    }

    public function pveTelegraphAvailable(BattleActor $actor, BattleState $state): bool
    {
        return in_array($state->battleType, ['normal', 'pve', 'boss'], true)
            && $actor === $state->player
            && $state->pendingEnemyActionId !== null
            && is_array($state->enemyTelegraphContext)
            && $this->featureGate->usesCDesignPrototype($actor);
    }

    public function markPveTelegraphExecuting(BattleState $state, bool $executing): void
    {
        if (is_array($state->enemyTelegraphContext)) {
            $state->enemyTelegraphContext['executing'] = $executing;
        }
    }

    public function completePveTelegraphedEnemyAction(BattleState $state): void
    {
        $context = $state->enemyTelegraphContext;
        if (! is_array($context)) {
            return;
        }
        if (! empty($context['eclipse_backlash'])) {
            $enemy = $state->enemy;
            $damage = min(max(1, (int) floor($enemy->maxHp * 0.05)), max(0, $enemy->hp - 1));
            if ($damage > 0) {
                $enemy->takeDamage($damage);
            }
            $state->addLog('<span class="text-fuchsia-900 font-extrabold">冥蝕反噬が敵を蝕み、'.e((string) $damage).' の非致死ダメージを与えた！</span>');
        }
        if (! empty($context['resource_slow'])) {
            $state->enemy->jobArtV2UltimateCounterplayState()->resourceGainPenaltyCharges = max(
                $state->enemy->jobArtV2UltimateCounterplayState()->resourceGainPenaltyCharges,
                2,
            );
        }
        $state->enemyTelegraphContext = null;
        $state->player->jobArtV2UltimateCounterplayState()->ultimateGuard = null;
    }

    private function beginPveTelegraphResponse(BattleActor $actor, BattleState $state, Skill $skill, int $sourceActionId): void
    {
        $effect = $this->catalog->effectFor($skill);
        if ($effect === null || ! is_array($state->enemyTelegraphContext)) {
            return;
        }
        $state->updateJobArtV2RoleAction($sourceActionId, [
            'ultimate_counterplay_response_effect' => $effect,
            'ultimate_counterplay_pve_telegraph' => true,
            'ultimate_counterplay_target_key' => $state->actorKey($state->enemy),
        ]);
        if ($effect === JobArtV2UltimateCounterplayCatalog::COUNTER_INTERCEPT
            || $effect === JobArtV2UltimateCounterplayCatalog::ULTIMATE_GUARD
        ) {
            $rate = $effect === JobArtV2UltimateCounterplayCatalog::COUNTER_INTERCEPT ? 0.20 : 0.35;
            $actor->replaceJobArtV2GuardState(null);
            $actor->jobArtV2UltimateCounterplayState()->ultimateGuard = new JobArtV2UltimateGuardState(
                targetActorKey: $state->actorKey($state->enemy),
                targetCycleId: (int) $state->enemyTelegraphContext['cycle_id'],
                rate: $rate,
                effect: $effect,
                responseSkillId: (int) $skill->id,
            );
        }
        if ($effect === JobArtV2UltimateCounterplayCatalog::READINESS_DELAY) {
            if (empty($state->enemyTelegraphContext['delayed'])) {
                $state->pendingEnemyActionTurns++;
                $state->enemyTelegraphContext['delayed'] = true;
                $state->addLog('<span class="text-violet-700 font-bold">敵の大技を1行動遅らせた！</span>');
            }
        }
        if ($effect === JobArtV2UltimateCounterplayCatalog::FIELD_SUPPRESSION) {
            $state->enemyTelegraphContext['lineage_suppressed'] = true;
        }
    }

    private function completePveTelegraphResponse(
        BattleActor $actor,
        BattleActor $target,
        BattleState $state,
        string $effect,
        ?HitResult $hitResult,
    ): void {
        if ($hitResult?->landed() !== true || ! is_array($state->enemyTelegraphContext)) {
            return;
        }
        if ($effect === JobArtV2UltimateCounterplayCatalog::ECLIPSE_BACKLASH) {
            $state->enemyTelegraphContext['eclipse_backlash'] = true;
        } elseif ($effect === JobArtV2UltimateCounterplayCatalog::AIM_SP_PRESSURE && $target->maxMp > 0) {
            $lost = min($target->mp, max(1, (int) floor($target->maxMp * 0.03)));
            $target->mp -= $lost;
        } elseif ($effect === JobArtV2UltimateCounterplayCatalog::HUNT_CANCEL
            && empty($state->enemyTelegraphContext['delayed'])
        ) {
            $state->pendingEnemyActionTurns++;
            $state->enemyTelegraphContext['delayed'] = true;
            $state->addLog('<span class="text-slate-800 font-bold">影縫いが敵の大技を1行動遅らせた！</span>');
        } elseif ($effect === JobArtV2UltimateCounterplayCatalog::TRANSMUTE_RESOURCE_SLOW) {
            $resource = $this->resourceCatalog->forActor($target);
            if (is_array($resource)) {
                $state->enemyTelegraphContext['resource_slow'] = true;
                $state->enemyTelegraphContext['resource_key'] = (string) $resource['resource_key'];
                $state->addLog('<span class="text-amber-800 font-bold">大錬成爆装が敵の次の資源循環を鈍らせた！</span>');
            }
        } elseif ($effect === JobArtV2UltimateCounterplayCatalog::BREAK_PREPARATION
            && $this->hasDestroyablePvePreparation($target)
            && $this->progressionService->consumeBreakMarksFor($target, $actor, 1)
        ) {
            $this->destroyPvePreparation($target, $state);
        }
    }

    private function hasDestroyablePvePreparation(BattleActor $target): bool
    {
        if ($target->jobArtV2GuardState() !== null || $target->counterStanceState() !== null) {
            return true;
        }
        foreach ($target->jobArtV2TimedEffects() as $effect) {
            if (! $effect->isExpired()
                && array_filter($effect->statModifiers, static fn (float $value): bool => $value > 0.0) !== []
            ) {
                return true;
            }
        }

        return false;
    }

    private function destroyPvePreparation(BattleActor $target, BattleState $state): void
    {
        if ($target->jobArtV2GuardState() !== null) {
            $target->replaceJobArtV2GuardState(null);

            return;
        }
        if ($target->counterStanceState() !== null) {
            $target->replaceCounterStanceState(null);

            return;
        }
        $positive = array_values(array_filter(
            $target->jobArtV2TimedEffects(),
            static fn (JobArtV2TimedEffectState $effect): bool => ! $effect->isExpired()
                && array_filter($effect->statModifiers, static fn (float $value): bool => $value > 0.0) !== [],
        ));
        usort($positive, static fn (JobArtV2TimedEffectState $a, JobArtV2TimedEffectState $b): int => $b->strength <=> $a->strength);
        if ($positive !== []) {
            if ($this->progressionService->preventTimedBuffReduction($target, $state)) {
                return;
            }
            $target->removeJobArtV2TimedEffect($positive[0]->key);
        }
    }

    /** @return array{phase:?string,status_label:string,cycle_id:?int,delay_actions:int,main_rank_five_established:bool}|null */
    public function hudSnapshot(BattleActor $actor, BattleState $state): ?array
    {
        if (! $this->enabledFor($state)) {
            return null;
        }

        $actorState = $actor->existingJobArtV2UltimateCounterplayState();
        $preparation = $actorState?->preparation;
        $ultimate = $this->equippedUltimate($actor);
        $resource = $ultimate !== null
            ? $this->resourceCatalog->forActorArt($actor, $ultimate)
            : null;
        $required = max(1, (int) ($resource['minimum_resource_points'] ?? 12));
        $full = $resource !== null
            && $actor->getResource((string) $resource['resource_key']) >= $required;
        $status = match (true) {
            $preparation?->isPreparing() === true => '奥義予告中（相手の応答待ち）',
            $preparation?->isReady() === true && $preparation->delayOwnActionsRemaining > 0 => '奥義発動まであと1行動',
            $preparation?->isReady() === true => '奥義を使用可能',
            $full => '奥義予告待ち',
            default => '奥義資源を蓄積中',
        };

        return [
            'phase' => $preparation?->phase,
            'status_label' => $status,
            'cycle_id' => $preparation?->cycleId,
            'delay_actions' => $preparation?->delayOwnActionsRemaining ?? 0,
            'main_rank_five_established' => (bool) ($actorState?->mainRankFiveEstablished),
            'resource_key' => $resource['resource_key'] ?? null,
        ];
    }

    /** @return list<string> */
    public function effectTextsForDisplay(BattleActor $actor, Skill $skill): array
    {
        if (! (bool) config('battle.job_art_v2.ultimate_counterplay', false)) {
            return [];
        }

        $texts = [];
        $effectText = $this->catalog->effectText($skill);
        if ($effectText !== null && $this->isFormalArt($actor, $skill)) {
            $texts[] = $effectText;
        }
        if ($this->isMainRankNine($actor, $skill)) {
            $resource = $this->resourceCatalog->forActorArt($actor, $skill);
            $required = max(1, (int) ($resource['minimum_resource_points'] ?? 12));
            $texts[] = '［対人戦］必要な資源が'.$required.'に達すると奥義を予告する。相手の次の1行動後に発動可能になる。奥義実行または準備中断後は、資源が必要量に達していれば再び予告する';
        }

        return $texts;
    }

    private function tryEnterPreparation(BattleActor $actor, BattleState $state): void
    {
        $actorState = $actor->jobArtV2UltimateCounterplayState();
        if ($actorState->preparation !== null
            || $actorState->huntCancelResistance
            || ! $this->hasMainRankNine($actor)
        ) {
            return;
        }

        $ultimate = $this->equippedUltimate($actor);
        $resource = $ultimate !== null
            ? $this->resourceCatalog->forActorArt($actor, $ultimate)
            : null;
        $sourceActionId = $state->currentSourceActionId();
        if ($resource === null || $sourceActionId === null) {
            return;
        }

        $resourceKey = (string) $resource['resource_key'];
        $required = max(1, (int) ($resource['minimum_resource_points'] ?? 12));
        if ($actor->getResource($resourceKey) < $required) {
            return;
        }

        $cycleId = $actorState->nextCycleId++;
        $actorState->preparation = new JobArtV2UltimatePreparationState(
            cycleId: $cycleId,
            mainLineage: (string) $resource['lineage_key'],
            resourceKey: $resourceKey,
            preparedSourceActionId: $sourceActionId,
            requiredPoints: $required,
        );
        $state->addLog('<span class="text-amber-700 font-extrabold">'.e($actor->name).' が奥義を予告した！ 相手は次の1行動で応答できる。</span>');
    }

    private function validatePreparationResource(BattleActor $actor, BattleState $state): void
    {
        $actorState = $actor->jobArtV2UltimateCounterplayState();
        $preparation = $actorState->preparation;
        if ($preparation === null
            || $actor->getResource($preparation->resourceKey) >= $preparation->requiredPoints
        ) {
            return;
        }

        $cycleId = $preparation->cycleId;
        $actorState->preparation = null;
        $actorState->mainRankFiveEstablished = false;
        $actorState->establishedUltimateLineage = null;
        $this->clearCycleEffects($state, $actor, $cycleId);
        $state->addLog('<span class="text-slate-600 font-bold">'.e($actor->name).' の奥義準備が解除された。</span>');
    }

    private function clearCycleEffects(BattleState $state, BattleActor $target, int $cycleId): void
    {
        $targetState = $target->jobArtV2UltimateCounterplayState();
        foreach (['eclipseBacklash', 'lineageSuppression', 'pendingResourceSlow'] as $property) {
            $effect = $targetState->{$property};
            if ($effect !== null && $effect->targetCycleId === $cycleId) {
                $targetState->{$property} = null;
            }
        }
        $this->clearGuardsForCycle($state, $state->actorKey($target), $cycleId);
    }

    private function clearGuardsForCycle(BattleState $state, string $targetActorKey, int $cycleId): void
    {
        foreach ([$state->player, $state->enemy] as $actor) {
            $actorState = $actor->existingJobArtV2UltimateCounterplayState();
            $guard = $actorState?->ultimateGuard;
            if ($guard !== null
                && $guard->targetActorKey === $targetActorKey
                && $guard->targetCycleId === $cycleId
            ) {
                $actorState->ultimateGuard = null;
            }
            foreach ($actorState?->consumedUltimateGuards ?? [] as $sourceActionId => $consumed) {
                if ($consumed->targetActorKey === $targetActorKey
                    && $consumed->targetCycleId === $cycleId
                ) {
                    unset($actorState->consumedUltimateGuards[$sourceActionId]);
                }
            }
        }
    }

    private function hasDestroyableUltimatePreparation(BattleActor $target, string $mainLineage): bool
    {
        foreach ($target->jobArtV2PreparedEffects() as $prepared) {
            if (! $prepared->isExpired()
                && $prepared->targetLineage === $mainLineage
                && in_array(9, $prepared->targetRanks, true)
            ) {
                return true;
            }
        }

        return ($mainLineage === 'pierce' && $this->progressionService->superPierceStanceActive($target))
            || ($mainLineage === 'guard' && $target->jobArtV2GuardState() !== null)
            || ($mainLineage === 'counter' && $target->counterStanceState() !== null);
    }

    private function destroyUltimatePreparation(BattleActor $target, string $mainLineage): ?string
    {
        $prepared = array_values(array_filter(
            $target->jobArtV2PreparedEffects(),
            static fn (JobArtV2PreparedEffectState $effect): bool => ! $effect->isExpired()
                && $effect->targetLineage === $mainLineage
                && in_array(9, $effect->targetRanks, true),
        ));
        usort($prepared, static fn (JobArtV2PreparedEffectState $left, JobArtV2PreparedEffectState $right): int => strcmp($left->key, $right->key));
        if ($prepared !== []) {
            $target->removeJobArtV2PreparedEffect($prepared[0]->key);

            return $prepared[0]->key;
        }

        if ($mainLineage === 'pierce' && $this->progressionService->consumeSuperPierceStance($target)) {
            return '貫通構え';
        }
        if ($mainLineage === 'guard' && $target->jobArtV2GuardState() !== null) {
            $target->replaceJobArtV2GuardState(null);

            return '障壁';
        }
        if ($mainLineage === 'counter' && $target->counterStanceState() !== null) {
            $target->replaceCounterStanceState(null);

            return '反撃構え';
        }

        return null;
    }

    private function hasMainRankNine(BattleActor $actor): bool
    {
        return $this->equippedUltimate($actor) !== null;
    }

    private function isMainRankFive(BattleActor $actor, Skill $skill): bool
    {
        if ((int) $skill->learn_rank !== 5 || ! $this->isFormalArt($actor, $skill)) {
            return false;
        }

        $ultimate = $this->equippedUltimate($actor);
        $chain = $this->resourceCatalog->forActorArt($actor, $skill);
        $finisher = $ultimate !== null
            ? $this->resourceCatalog->forActorArt($actor, $ultimate)
            : null;

        return $chain !== null
            && $finisher !== null
            && (string) $chain['lineage_key'] === (string) $finisher['lineage_key'];
    }

    private function isMainRankNine(BattleActor $actor, Skill $skill): bool
    {
        $ultimate = $this->equippedUltimate($actor);

        return $ultimate !== null
            && (int) $skill->learn_rank === 9
            && (int) $skill->job_id === (int) $ultimate->job_id
            && (string) $skill->name === (string) $ultimate->name;
    }

    private function isFormalArt(BattleActor $actor, Skill $skill): bool
    {
        $resolution = $this->deckRoleResolver->resolveActor($actor);

        return $this->resourceCatalog->forActorArt($actor, $skill) !== null
            && $resolution->blockReasonFor($skill) === null;
    }

    private function equippedUltimate(BattleActor $actor): ?Skill
    {
        foreach ($actor->jobArts as $skill) {
            if (! $skill instanceof Skill || (int) $skill->learn_rank !== 9) {
                continue;
            }
            if ($this->resourceCatalog->forActorArt($actor, $skill) !== null
                && $this->deckRoleResolver->eligibilityBlockReason($actor, $skill) === null
            ) {
                return $skill;
            }
        }

        return null;
    }

    private function opponent(BattleActor $actor, BattleState $state): BattleActor
    {
        return $actor === $state->player ? $state->enemy : $state->player;
    }
}
