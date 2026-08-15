<?php

namespace App\Services;

use App\Models\Character;
use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\ActionResolver;
use App\Services\Battle\HitResult;

class JobArtBattleSupportService
{
    private readonly ActionResolver $jobArtActionResolver;
    private readonly JobArtV2ResourceService $jobArtV2ResourceService;
    private readonly JobArtV2FieldService $jobArtV2FieldService;
    private readonly JobArtV2PenetrationService $jobArtV2PenetrationService;
    private readonly JobArtV2PenetrationStanceService $jobArtV2PenetrationStanceService;
    private readonly JobArtV2BattleHudService $jobArtV2BattleHudService;
    private readonly JobArtV2PowerResolver $jobArtV2PowerResolver;
    private readonly JobArtV2DamageSemanticsResolver $jobArtV2DamageSemanticsResolver;
    private readonly JobArtV2SpPressureService $jobArtV2SpPressureService;
    private readonly JobArtV2BreakDebuffService $jobArtV2BreakDebuffService;
    private readonly JobArtV2EffectSemanticsResolver $jobArtV2EffectSemanticsResolver;
    private readonly JobArtV2DefenseService $jobArtV2DefenseService;
    private readonly JobArtV2RoleEffectService $jobArtV2RoleEffectService;
    private readonly JobArtV2ProgressionService $jobArtV2ProgressionService;
    private readonly JobArtV2UltimateCounterplayService $jobArtV2UltimateCounterplayService;
    private readonly JobArtV2CrownBalanceCatalog $jobArtV2CrownBalanceCatalog;
    private readonly JobArtFlavorTextService $jobArtFlavorTextService;

    public function __construct(
        private readonly JobArtService $jobArtService,
        private readonly JobArtV2FeatureGate $jobArtV2FeatureGate,
        private readonly JobArtV2SelectionService $jobArtV2SelectionService,
        private readonly JobArtV2SpCostCalculator $jobArtV2SpCostCalculator,
        ?ActionResolver $jobArtActionResolver = null,
        ?JobArtV2ResourceService $jobArtV2ResourceService = null,
        ?JobArtV2FieldService $jobArtV2FieldService = null,
        ?JobArtV2PenetrationService $jobArtV2PenetrationService = null,
        ?JobArtV2PenetrationStanceService $jobArtV2PenetrationStanceService = null,
        ?JobArtV2BattleHudService $jobArtV2BattleHudService = null,
        ?JobArtV2PowerResolver $jobArtV2PowerResolver = null,
        ?JobArtV2DamageSemanticsResolver $jobArtV2DamageSemanticsResolver = null,
        ?JobArtV2SpPressureService $jobArtV2SpPressureService = null,
        ?JobArtV2BreakDebuffService $jobArtV2BreakDebuffService = null,
        ?JobArtV2EffectSemanticsResolver $jobArtV2EffectSemanticsResolver = null,
        ?JobArtV2DefenseService $jobArtV2DefenseService = null,
        ?JobArtV2RoleEffectService $jobArtV2RoleEffectService = null,
        ?JobArtV2ProgressionService $jobArtV2ProgressionService = null,
        ?JobArtV2UltimateCounterplayService $jobArtV2UltimateCounterplayService = null,
        ?JobArtV2CrownBalanceCatalog $jobArtV2CrownBalanceCatalog = null,
        ?JobArtFlavorTextService $jobArtFlavorTextService = null,
    ) {
        $this->jobArtActionResolver = $jobArtActionResolver ?? app(ActionResolver::class);
        $this->jobArtV2ResourceService = $jobArtV2ResourceService ?? app(JobArtV2ResourceService::class);
        $this->jobArtV2FieldService = $jobArtV2FieldService ?? app(JobArtV2FieldService::class);
        $this->jobArtV2PenetrationService = $jobArtV2PenetrationService ?? app(JobArtV2PenetrationService::class);
        $this->jobArtV2PenetrationStanceService = $jobArtV2PenetrationStanceService ?? app(JobArtV2PenetrationStanceService::class);
        $this->jobArtV2BattleHudService = $jobArtV2BattleHudService ?? app(JobArtV2BattleHudService::class);
        $this->jobArtV2PowerResolver = $jobArtV2PowerResolver ?? app(JobArtV2PowerResolver::class);
        $this->jobArtV2DamageSemanticsResolver = $jobArtV2DamageSemanticsResolver ?? app(JobArtV2DamageSemanticsResolver::class);
        $this->jobArtV2SpPressureService = $jobArtV2SpPressureService ?? app(JobArtV2SpPressureService::class);
        $this->jobArtV2BreakDebuffService = $jobArtV2BreakDebuffService ?? app(JobArtV2BreakDebuffService::class);
        $this->jobArtV2EffectSemanticsResolver = $jobArtV2EffectSemanticsResolver ?? app(JobArtV2EffectSemanticsResolver::class);
        $this->jobArtV2DefenseService = $jobArtV2DefenseService ?? app(JobArtV2DefenseService::class);
        $this->jobArtV2RoleEffectService = $jobArtV2RoleEffectService ?? app(JobArtV2RoleEffectService::class);
        $this->jobArtV2ProgressionService = $jobArtV2ProgressionService ?? app(JobArtV2ProgressionService::class);
        $this->jobArtV2UltimateCounterplayService = $jobArtV2UltimateCounterplayService
            ?? app(JobArtV2UltimateCounterplayService::class);
        $this->jobArtV2CrownBalanceCatalog = $jobArtV2CrownBalanceCatalog
            ?? app(JobArtV2CrownBalanceCatalog::class);
        $this->jobArtFlavorTextService = $jobArtFlavorTextService ?? app(JobArtFlavorTextService::class);
    }

    public function attachBossSet(BattleActor $actor, Character $character, string $context = 'champ'): void
    {
        $actor->currentJobId = $character->current_job_id !== null ? (int) $character->current_job_id : null;
        $actor->jobArtActivationPolicy = (string) ($character->job_art_activation_policy ?: 'normal');
        $jobArts = $this->jobArtService->battleArtsFor($character, $context);
        $actor->jobArts = $jobArts->all();

        foreach ($jobArts as $art) {
            $actor->jobArtRates[(int) $art->id] = (float) $art->getAttribute('job_art_rate');
            $actor->jobArtOrigins[(int) $art->id] = (string) $art->getAttribute('job_art_origin');
            $actor->jobArtPolicies[(int) $art->id] = (string) ($art->getAttribute('job_art_activation_policy') ?: $actor->jobArtActivationPolicy);
            $actor->jobArtConditions[(int) $art->id] = (string) ($art->getAttribute('job_art_slot_condition') ?: JobArtV2SlotConditionCatalog::ALWAYS);
        }
    }

    public function tickCooldowns(BattleState $state, BattleActor $actor): void
    {
        $prefix = $this->actorStatePrefix($actor);
        foreach ($state->jobArtCooldowns as $key => $remaining) {
            if (!str_starts_with((string) $key, $prefix)) {
                continue;
            }

            $remaining = max(0, (int) $remaining - 1);
            if ($remaining <= 0) {
                unset($state->jobArtCooldowns[$key]);
            } else {
                $state->jobArtCooldowns[$key] = $remaining;
            }
        }
    }

    public function usesDamageApplication(?BattleActor $source, BattleActor $target): bool
    {
        return $this->jobArtV2FeatureGate->usesDamageApplication($source, $target);
    }

    public function usesRoleEffects(BattleActor $actor): bool
    {
        return $this->jobArtV2RoleEffectService->enabledFor($actor);
    }

    /**
     * @return array{
     *     main_label: string,
     *     main_before: int,
     *     main_after: int,
     *     sub_label: string,
     *     sub_before: int,
     *     sub_after: int
     * }|null
     */
    public function applySharedSelfBuff(BattleActor $actor, Skill $skill): ?array
    {
        return $this->jobArtV2RoleEffectService->applySharedSelfBuff($actor, $skill);
    }

    /**
     * @return array{
     *     duration_turns: int,
     *     changes: list<array{stat: string, label: string, percent: int}>
     * }|null
     */
    public function applyTimedStructuredDebuffs(
        BattleActor $attacker,
        BattleActor $defender,
        BattleState $state,
        Skill $skill,
        float $rate = 1.0,
    ): ?array {
        return $this->jobArtV2RoleEffectService->applyTimedStructuredDebuffs(
            $attacker,
            $defender,
            $state,
            $skill,
            $rate,
        );
    }

    public function adjustInitiative(
        BattleActor $firstCandidate,
        BattleActor $secondCandidate,
        bool $firstCandidateWon,
        \Closure $reroll,
    ): bool {
        return $this->jobArtV2ProgressionService->adjustInitiative(
            $firstCandidate,
            $secondCandidate,
            $firstCandidateWon,
            $reroll,
        );
    }

    public function beginAction(BattleActor $actor, BattleState $state): ?int
    {
        $sourceActionId = $this->jobArtV2ResourceService->beginAction($actor, $state);
        if ($sourceActionId !== null) {
            $this->jobArtV2RoleEffectService->beginAction($actor, $state, $sourceActionId);
        }

        return $sourceActionId;
    }

    public function recordNormalAttackHit(BattleActor $actor, BattleState $state): ResourceChangeResult
    {
        $this->jobArtV2RoleEffectService->markNonJobArtAction($actor, $state);

        return $this->jobArtV2ResourceService->recordNormalAttackHit($actor, $state);
    }

    public function recordNormalAttackResolution(
        BattleActor $actor,
        BattleActor $target,
        BattleState $state,
        HitResult $hitResult,
        bool $markAction = true,
    ): ResourceChangeResult {
        if ($markAction) {
            $this->jobArtV2RoleEffectService->markNonJobArtAction($actor, $state);
        }

        return $this->jobArtV2ResourceService->recordNormalAttackResolution($actor, $target, $state, $hitResult);
    }

    public function markNormalAttackAction(BattleActor $actor, BattleState $state): void
    {
        $this->jobArtV2RoleEffectService->markNonJobArtAction($actor, $state);
    }

    public function recordSelfDamage(BattleActor $actor, BattleState $state, int $actualDamage): ResourceChangeResult
    {
        return $this->jobArtV2ResourceService->recordSelfDamage($actor, $state, $actualDamage);
    }

    public function markSkillAction(BattleActor $actor, BattleState $state, Skill $skill): void
    {
        if (!$skill->isJobArt()) {
            $this->jobArtV2RoleEffectService->markNonJobArtAction($actor, $state);
        }
        $this->jobArtV2ResourceService->markCurrentJobSkillAction($actor, $state, $skill);
        $this->jobArtV2FieldService->markSkillAction($actor, $state, $skill);
    }

    public function endRound(BattleState $state): array
    {
        return array_merge(
            $this->jobArtV2FieldService->endRound($state),
            $this->jobArtV2BreakDebuffService->endRound($state),
            $this->jobArtV2DefenseService->endRound($state),
            $this->jobArtV2RoleEffectService->endRound($state),
        );
    }

    public function finishAction(BattleActor $actor, BattleState $state): void
    {
        $this->jobArtV2ResourceService->finishAction($actor, $state);
        $this->jobArtV2UltimateCounterplayService->finishAction($actor, $state);
    }

    /** @return array<string, mixed>|null */
    public function battleHud(BattleState $state): ?array
    {
        return $this->jobArtV2BattleHudService->present($state);
    }

    public function modifyFieldDamage(BattleActor $actor, BattleState $state, int $damage, \App\Services\Battle\DamageSourceType $sourceType): int
    {
        if ($this->jobArtV2UltimateCounterplayService->currentActionLineageEffectsSuppressed($actor, $state)) {
            return $damage;
        }

        return $this->jobArtV2FieldService->modifyDamage($actor, $state, $damage, $sourceType);
    }

    public function modifyFieldHpHeal(BattleActor $actor, BattleState $state, int $heal): int
    {
        if ($this->jobArtV2UltimateCounterplayService->currentActionLineageEffectsSuppressed($actor, $state)) {
            return $this->jobArtV2ProgressionService->modifyHpHeal($actor, $state, $heal);
        }

        return $this->jobArtV2FieldService->modifyHpHeal($actor, $state, $heal);
    }

    public function applyFieldHpHeal(BattleActor $actor, BattleState $state, int $heal): int
    {
        if ($this->jobArtV2UltimateCounterplayService->currentActionLineageEffectsSuppressed($actor, $state)) {
            $actual = $actor->healHp($this->jobArtV2ProgressionService->modifyHpHeal($actor, $state, $heal));
            $this->jobArtV2ProgressionService->completeHpHeal($actor, $state);

            return $actual;
        }

        return $this->jobArtV2FieldService->applyHpHeal($actor, $state, $heal);
    }

    public function fieldAccuracyDelta(BattleActor $actor, BattleState $state): float
    {
        if ($this->jobArtV2UltimateCounterplayService->currentActionLineageEffectsSuppressed($actor, $state)) {
            return 0.0;
        }

        return $this->jobArtV2FieldService->accuracyDelta($actor, $state);
    }

    public function selectForTurn(BattleActor $actor, BattleState $state): ?Skill
    {
        if ($this->jobArtV2FeatureGate->usesDynamicSingle($actor)) {
            return $this->jobArtV2SelectionService->selectForTurn(
                $actor,
                $state,
                fn (Skill $skill): string => $this->actorSkillStateKey($actor, $skill),
            )->skill;
        }

        foreach ($actor->jobArts as $art) {
            if (!$art instanceof Skill) {
                continue;
            }

            $stateKey = $this->actorSkillStateKey($actor, $art);
            if (($state->jobArtCooldowns[$stateKey] ?? 0) > 0) {
                continue;
            }

            if ($art->max_uses_per_battle !== null
                && ($state->jobArtUseCounts[$stateKey] ?? 0) >= (int) $art->max_uses_per_battle
            ) {
                continue;
            }

            $spCost = $this->spCost($actor, $art);
            $policy = (string) ($actor->jobArtPolicies[(int) $art->id] ?? $actor->jobArtActivationPolicy);
            if (!$this->canActivateByPolicy($actor, $spCost, $policy)) {
                continue;
            }

            if (!$this->canActivateRecoveryArt($actor, $art)) {
                continue;
            }

            if (random_int(1, 100) <= $art->effectiveActivationRate()) {
                return $art;
            }
        }

        return null;
    }

    public function spCost(BattleActor $actor, Skill $skill): int
    {
        return $this->jobArtV2SpCostCalculator->forActor($actor, $skill);
    }

    public function consumeAndMarkUse(
        BattleActor $actor,
        BattleState $state,
        Skill $skill,
        ?string $activationLog = null,
    ): bool
    {
        $stateKey = $this->actorSkillStateKey($actor, $skill);
        if ($this->jobArtV2ResourceService->enabledFor($actor)
            && !$this->jobArtV2SelectionService->isEligible($actor, $state, $skill, $stateKey)
        ) {
            return false;
        }

        $actor->mp -= $this->spCost($actor, $skill);
        $state->jobArtUseCounts[$stateKey] = (int) ($state->jobArtUseCounts[$stateKey] ?? 0) + 1;

        if (! $this->jobArtV2FeatureGate->usesDynamicSingle($actor)
            && (int) $skill->cooldown_turns > 0
        ) {
            $state->jobArtCooldowns[$stateKey] = (int) $skill->cooldown_turns;
        }

        if ($activationLog !== null && trim($activationLog) !== '') {
            $state->addLog($activationLog);
        }

        $this->jobArtV2UltimateCounterplayService->beginJobArtCast($actor, $state, $skill);
        $this->jobArtV2ResourceService->applyJobArtCast($actor, $state, $skill);
        if (! $this->jobArtV2UltimateCounterplayService->lineageEffectsSuppressedForCurrentAction($actor, $state, $skill)) {
            $this->jobArtV2PenetrationStanceService->beginCast($actor, $state, $skill);
            $this->jobArtV2DefenseService->applyJobArtCast($actor, $state, $skill);
        }
        $this->jobArtV2RoleEffectService->beginJobArtCast($actor, $state, $skill);

        return true;
    }

    public function completeJobArtCast(
        BattleActor $actor,
        BattleState $state,
        Skill $skill,
        ?HitResult $hitResult = null,
        ?BattleActor $target = null,
    ): void
    {
        if ($hitResult?->landed()) {
            $this->jobArtV2ResourceService->recordJobArtHit($actor, $state, $skill);
        }
        $lineageSuppressed = $this->jobArtV2UltimateCounterplayService
            ->lineageEffectsSuppressedForCurrentAction($actor, $state, $skill);
        if ($target !== null && ! $lineageSuppressed) {
            $this->jobArtV2SpPressureService->applyOnHit($actor, $target, $state, $skill, $hitResult);
            $this->jobArtV2BreakDebuffService->applyOnHit($actor, $target, $state, $skill, $hitResult);
        }
        if (! $lineageSuppressed) {
            $this->jobArtV2PenetrationStanceService->completeCast($actor, $state, $skill);
        }
        if ($target !== null) {
            $this->jobArtV2UltimateCounterplayService->completeJobArtCast(
                $actor,
                $target,
                $state,
                $skill,
                $hitResult,
            );
            $this->jobArtV2RoleEffectService->completeJobArtCast($actor, $target, $state, $skill, $hitResult);
        }
    }

    public function skillForExecution(
        BattleActor $actor,
        Skill $skill,
        ?BattleState $state = null,
        ?BattleActor $defender = null,
    ): Skill
    {
        if ($state !== null) {
            $baseOnly = $this->jobArtV2UltimateCounterplayService
                ->baseOnlySkillForExecution($actor, $state, $skill);
            if ($baseOnly !== null) {
                return $baseOnly;
            }
        }

        $rate = (float) ($actor->jobArtRates[(int) $skill->id] ?? 1.0);
        // Every battle route must operate on an execution-only copy. Arts
        // without a crown-balance override previously reused the master model,
        // allowing damage semantics to mutate the source art in memory.
        $executionSkill = clone $skill;
        $this->jobArtV2CrownBalanceCatalog->applyToExistingExecution($executionSkill);
        $this->jobArtV2DamageSemanticsResolver->applyForExecution($actor, $skill, $executionSkill);
        $this->jobArtV2EffectSemanticsResolver->applyForExecution($actor, $skill, $executionSkill);
        if ($state !== null && $this->jobArtV2FieldService->isFieldOnlyArt($actor, $state, $skill)) {
            $executionSkill->power = 0;
            $executionSkill->power_multiplier = 0;
            $executionSkill->hit_count = 0;
            $executionSkill->damage_type = 'support';
            $executionSkill->effect_template = 'TIME_CONTROL_CURRENT_ONLY';

            return $executionSkill;
        }
        $basePower = $this->jobArtV2PowerResolver->forExecution($actor, $skill, $state);
        $power = max(0, (int) round(($basePower ?: 100) * $rate));
        $executionSkill->power = $power;
        $executionSkill->power_multiplier = max(0, $power / 100);
        if ($state !== null && $defender !== null) {
            $this->jobArtV2RoleEffectService->applyForExecution($actor, $defender, $state, $skill, $executionSkill);
            $this->jobArtV2PowerResolver->applyFinalDamageModifiers(
                $actor,
                $skill,
                $executionSkill,
                $state,
            );
            $this->jobArtV2UltimateCounterplayService->applyForExecution(
                $actor,
                $defender,
                $state,
                $skill,
                $executionSkill,
            );
            $this->jobArtV2FieldService->markSkillAction($actor, $state, $executionSkill);
            if ((string) $executionSkill->effect_template === 'V2_ROLE_EFFECT_ONLY') {
                $executionSkill->power = 0;
                $executionSkill->power_multiplier = 0;
                $executionSkill->hit_count = 0;
                $executionSkill->damage_type = 'support';
                $executionSkill->setAttribute('extra_hit_chance_percent', 0);
                $executionSkill->setAttribute('luk_power_rate', 0.0);
            }
        }

        return $executionSkill;
    }

    public function modifyJobArtDamage(BattleActor $actor, BattleState $state, Skill $skill, int $damage): int
    {
        return $this->jobArtV2RoleEffectService->modifyJobArtDamage($actor, $state, $skill, $damage);
    }

    /** @return array{attack: ?int, def: ?int, spr: ?int} */
    public function damageStatOverrides(BattleActor $attacker, BattleActor $defender, Skill $skill): array
    {
        return $this->jobArtV2RoleEffectService->damageStatOverrides($attacker, $defender, $skill);
    }

    public function criticalBonusPoints(BattleActor $actor, Skill $skill): float
    {
        return $this->jobArtV2RoleEffectService->criticalBonusPoints($actor, $skill);
    }

    public function isFieldOnlyArt(BattleActor $actor, BattleState $state, Skill $skill): bool
    {
        return $this->jobArtV2FieldService->isFieldOnlyArt($actor, $state, $skill);
    }

    public function activationLog(BattleActor $attacker, BattleActor $defender, Skill $skill): string
    {
        $lines = [
            '<span class="text-indigo-700 font-extrabold">《' . e($skill->name) . '》が発動！</span>',
        ];

        $flavorText = $this->jobArtFlavorTextService->resolve($skill);
        $phrase = trim((string) ($flavorText['activation_phrase'] ?? ''));
        if ($phrase !== '') {
            $lines[] = '<span class="text-slate-700 font-bold">' . e($this->formatFlavorText($phrase, $attacker, $defender, $skill)) . '</span>';
        }

        $description = trim((string) ($flavorText['activation_description'] ?? ''));
        if ($description !== '') {
            $lines[] = '<span class="text-indigo-800 font-bold">' . e($this->formatFlavorText($description, $attacker, $defender, $skill)) . '</span>';
        }

        return implode('<br>', $lines);
    }

    public function resolveHit(
        BattleActor $attacker,
        BattleActor $defender,
        Skill $skill,
        string $battleType,
        ?BattleState $state = null,
    ): ?HitResult {
        $result = $this->jobArtActionResolver->resolveJobArt($attacker, $defender, $skill, $battleType, $state);
        if ($state !== null) {
            $this->jobArtV2BattleHudService->recordHitResult($attacker, $state, $result);
        }

        return $result;
    }

    public function resolutionFailureLog(Skill $skill, HitResult $result): string
    {
        $verb = $result === HitResult::EVADE ? '回避された' : '外れた';

        return '<span class="text-slate-600 font-bold">' . e((string) $skill->name) . "は{$verb}！</span>";
    }

    /** @return array{def: ?int, spr: ?int, penetration_rate: ?float} */
    public function defenseOverrides(BattleActor $attacker, BattleActor $defender, BattleState $state, Skill $skill): array
    {
        if ($this->jobArtV2UltimateCounterplayService->lineageEffectsSuppressedForCurrentAction($attacker, $state, $skill)) {
            return ['def' => null, 'spr' => null, 'penetration_rate' => null];
        }
        if ($this->jobArtV2PenetrationStanceService->enabledFor($attacker)) {
            return $this->jobArtV2PenetrationStanceService->defenseOverrides($attacker, $defender, $state, $skill);
        }

        return $this->jobArtV2PenetrationService->defenseOverrides($attacker, $defender, $skill);
    }

    private function canActivateByPolicy(BattleActor $actor, int $spCost, string $policy): bool
    {
        if ($actor->mp < $spCost) {
            return false;
        }

        $spRate = $actor->maxMp > 0 ? $actor->mp / $actor->maxMp : 0.0;

        return match ($policy) {
            'aggressive' => true,
            'conserve' => $spRate >= 0.60,
            default => $spRate >= 0.30,
        };
    }

    private function canActivateRecoveryArt(BattleActor $actor, Skill $skill): bool
    {
        $skill = $this->jobArtV2CrownBalanceCatalog->applyToExecution($skill);
        if ($this->jobArtV2RoleEffectService->supportEffectCanBeMeaningful($actor, $skill)) {
            return true;
        }

        if ($this->jobArtV2RoleEffectService->enabledFor($actor)
            && $this->jobArtV2EffectSemanticsResolver->suppressesLegacySelfBuff($actor, $skill)
            && in_array(
                $this->jobArtV2EffectSemanticsResolver->replacementEffectTemplateForDisplay($actor->currentJobId, $skill),
                ['V2_ROLE_EFFECT_ONLY', 'HEAL', 'HEAL_CLEANSE', 'GUARD_BARRIER', 'SELF_BUFF'],
                true,
            )
        ) {
            return false;
        }

        $needsHp = $skill->isHealArt()
            || in_array((string) $skill->effect_template, ['HEAL', 'HEAL_CLEANSE'], true)
            || ((string) $skill->effect_template === 'DRAIN' && (float) $skill->drain_hp_rate > 0)
            || (int) $skill->heal_percent > 0;
        $needsSp = (int) $skill->mp_recover_percent > 0;
        if ($needsHp || $needsSp) {
            return ($needsHp && $this->hasMissingHp($actor))
                || ($needsSp && $this->hasMissingSp($actor));
        }

        return true;
    }

    private function hasMissingHp(BattleActor $actor): bool
    {
        return $actor->maxHp > 0 && $actor->hp < $actor->maxHp;
    }

    private function hasMissingSp(BattleActor $actor): bool
    {
        return $actor->maxMp > 0 && $actor->mp < $actor->maxMp;
    }

    private function actorSkillStateKey(BattleActor $actor, Skill $skill): string
    {
        return $this->actorStatePrefix($actor) . (int) $skill->id;
    }

    private function actorStatePrefix(BattleActor $actor): string
    {
        return spl_object_id($actor) . ':';
    }

    private function formatFlavorText(string $text, BattleActor $attacker, BattleActor $defender, Skill $skill): string
    {
        return strtr($text, [
            '{user}' => $attacker->name,
            '{target}' => $defender->name,
            '{skill}' => (string) $skill->name,
        ]);
    }
}
