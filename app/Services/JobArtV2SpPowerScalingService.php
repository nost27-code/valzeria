<?php

namespace App\Services;

use App\Models\Skill;
use App\Services\Battle\BattleActor;

/** Integer-only source of truth for variable SP cost and displayed-power gain. */
final class JobArtV2SpPowerScalingService
{
    public const NEUTRAL_BPS = 10000;

    private const BASIS_POINTS_DIVISOR = 10_000;

    private const SP_GENERATING_SUPPORT_ELIGIBILITY = 'sp_recoverable';

    public function __construct(
        private readonly JobArtV2FeatureGate $featureGate,
        private readonly JobArtV2EffectClassifier $effectClassifier,
        private readonly JobArtV2CrownBalanceCatalog $crownBalanceCatalog,
        private readonly JobArtV2RoleEffectCatalog $roleEffectCatalog,
        private readonly JobArtV2PrototypeCatalog $prototypeCatalog,
    ) {
    }

    public function enabledForActor(BattleActor $actor): bool
    {
        return $this->featureGate->usesSpPowerScaling($actor)
            && $actor->spScalingEligible;
    }

    public function isEligibleArt(Skill $skill): bool
    {
        return $this->exclusionReason($skill) === null;
    }

    public function exclusionReason(Skill $skill): ?string
    {
        if (! $skill->isJobArt() || ! in_array((int) $skill->learn_rank, [1, 5, 9], true)) {
            return 'not_v2_job_art';
        }

        if (! $this->effectClassifier->isSpOutputDamageArt($skill)) {
            return 'not_direct_damage';
        }

        if ($this->recoversSpFromMaster($skill)) {
            return 'recovers_sp';
        }

        if ($this->recoversSpFromRoleEffect($skill)) {
            return 'recovers_sp_role_effect';
        }

        if ($this->convertsHpToSp($skill)) {
            return 'hp_to_sp_conversion';
        }

        return null;
    }

    public function forActor(
        BattleActor $actor,
        Skill $skill,
        int $fixedCost,
        int $discountedFixedCost,
    ): JobArtV2SpPowerScalingResult {
        if (! $this->enabledForActor($actor) || ! $this->isEligibleArt($skill)) {
            return JobArtV2SpPowerScalingResult::fixedOnly($fixedCost, $discountedFixedCost);
        }

        return $this->build(
            fixedCost: $fixedCost,
            discountedFixedCost: $discountedFixedCost,
            powerReference: $actor->spPowerReference,
            learnRank: (int) $skill->learn_rank,
            outputKey: $this->outputForActor($actor),
            outputBudgetInitial: $actor->spOutputBudgetInitial(),
            outputBudgetRemaining: $actor->spOutputBudgetRemaining(),
        );
    }

    public function forReference(
        Skill $skill,
        ?int $currentJobId,
        int $powerReference,
        int $fixedCost,
        string $outputKey,
        ?int $discountedFixedCost = null,
        string $context = 'normal',
    ): JobArtV2SpPowerScalingResult {
        if (! $this->featureGate->usesSpPowerScalingForCurrentJob($currentJobId, $context)
            || $powerReference < 0
            || ! $this->isEligibleArt($skill)
        ) {
            return JobArtV2SpPowerScalingResult::fixedOnly($fixedCost, $discountedFixedCost ?? $fixedCost);
        }

        return $this->build(
            fixedCost: $fixedCost,
            discountedFixedCost: $discountedFixedCost ?? $fixedCost,
            powerReference: $powerReference,
            learnRank: (int) $skill->learn_rank,
            outputKey: app(JobArtV2StrategyService::class)->normalizeOutput($outputKey),
            outputBudgetInitial: null,
            outputBudgetRemaining: null,
        );
    }

    public function variableCostFor(int $powerReference, int $learnRank, string $outputKey): int
    {
        $outputKey = app(JobArtV2StrategyService::class)->normalizeOutput($outputKey);
        $costBps = max(0, (int) config(
            "battle.job_art_v2.sp_power_scaling.variable_cost_bps.{$learnRank}.{$outputKey}",
            0,
        ));
        if ($costBps === 0 || $outputKey === JobArtV2StrategyService::OUTPUT_NONE) {
            return 0;
        }

        return max(1, intdiv(
            max(0, $powerReference) * $costBps,
            self::BASIS_POINTS_DIVISOR,
        ));
    }

    /** @return array{linear:int, excess:int, total:int} */
    public function bonusPartsFor(int $powerReference, string $outputKey): array
    {
        $output = $this->outputConfig($outputKey);
        $stage = $output['stage'];
        if ($powerReference <= 0 || $stage === 0) {
            return ['linear' => 0, 'excess' => 0, 'total' => 0];
        }

        $linearLimit = (int) config('battle.job_art_v2.sp_power_scaling.linear_limit', 10_000);
        $linearDivisor = (int) config('battle.job_art_v2.sp_power_scaling.linear_divisor', 20);
        $excessDivisor = (int) config('battle.job_art_v2.sp_power_scaling.excess_divisor', 200);
        $linear = intdiv(min($powerReference, $linearLimit) * $stage, max(1, $linearDivisor));
        $excess = $powerReference > $linearLimit
            ? intdiv(($powerReference - $linearLimit) * $stage, max(1, $excessDivisor))
            : 0;

        return [
            'linear' => $linear,
            'excess' => $excess,
            'total' => min($output['cap_bps'], $linear + $excess),
        ];
    }

    public function initialBudgetFor(int $powerReference): int
    {
        $percent = max(0, (int) config('battle.job_art_v2.sp_power_scaling.output_budget_percent', 25));

        return intdiv(max(0, $powerReference) * $percent, 100);
    }

    private function build(
        int $fixedCost,
        int $discountedFixedCost,
        int $powerReference,
        int $learnRank,
        string $outputKey,
        ?int $outputBudgetInitial,
        ?int $outputBudgetRemaining,
    ): JobArtV2SpPowerScalingResult {
        $fixedCost = max(0, $fixedCost);
        $discountedFixedCost = max(0, $discountedFixedCost);
        $outputKey = app(JobArtV2StrategyService::class)->normalizeOutput($outputKey);
        $variableCost = $this->variableCostFor($powerReference, $learnRank, $outputKey);
        $bonus = $this->bonusPartsFor($powerReference, $outputKey);

        return new JobArtV2SpPowerScalingResult(
            fixedCost: $fixedCost,
            discountedFixedCost: $discountedFixedCost,
            variableCost: $variableCost,
            totalCost: $discountedFixedCost + $variableCost,
            linearBonusBps: $bonus['linear'],
            excessBonusBps: $bonus['excess'],
            bonusBps: $bonus['total'],
            basePowerBps: self::NEUTRAL_BPS,
            outputKey: $outputKey,
            powerReference: $powerReference,
            powerScalingApplies: $variableCost > 0 && $bonus['total'] > 0,
            outputBudgetInitial: $outputBudgetInitial,
            outputBudgetRemaining: $outputBudgetRemaining,
        );
    }

    /** @return array{stage:int, cap_bps:int} */
    private function outputConfig(string $outputKey): array
    {
        $outputKey = app(JobArtV2StrategyService::class)->normalizeOutput($outputKey);
        $configured = (array) config("battle.job_art_v2.sp_power_scaling.outputs.{$outputKey}", []);

        return [
            'stage' => max(0, min(4, (int) ($configured['stage'] ?? 0))),
            'cap_bps' => max(0, (int) ($configured['cap_bps'] ?? 0)),
        ];
    }

    private function outputForActor(BattleActor $actor): string
    {
        $profile = is_array($actor->jobArtStrategy) ? $actor->jobArtStrategy : [];

        return app(JobArtV2StrategyService::class)->normalizeOutput(
            $profile['sp_output'] ?? ($profile['settings']['sp_output'] ?? null),
        );
    }

    private function recoversSpFromMaster(Skill $skill): bool
    {
        return (int) $this->crownBalanceCatalog->applyToExecution($skill)->mp_recover_percent > 0;
    }

    private function recoversSpFromRoleEffect(Skill $skill): bool
    {
        $metadata = $this->roleEffectCatalog->forArt($skill) ?? [];
        $rank5 = $this->roleEffectCatalog->rank5V6MetadataForArt($skill) ?? [];
        $metadata = array_replace_recursive($metadata, $rank5);

        if (isset($metadata['heal']['sp'])) {
            return true;
        }

        if ((float) ($metadata['guard']['on_trigger']['sp_recovery_rate'] ?? 0) > 0) {
            return true;
        }

        if (is_array($metadata['adaptive_sustain'] ?? null)) {
            return true;
        }

        return in_array(
            self::SP_GENERATING_SUPPORT_ELIGIBILITY,
            (array) ($metadata['support_eligibility'] ?? []),
            true,
        );
    }

    private function convertsHpToSp(Skill $skill): bool
    {
        $metadata = $this->prototypeCatalog->artResourceMetadata($skill) ?? [];

        return ($metadata['resource_gain_event'] ?? null)
            === ResourceEvent::HP_SP_CONVERSION_SUCCESS->value;
    }
}
