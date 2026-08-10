<?php

namespace App\Services;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\HitResult;

final class JobArtV2SpPressureService
{
    public function __construct(
        private readonly JobArtV2FeatureGate $featureGate,
        private readonly JobArtV2PrototypeCatalog $prototypeCatalog,
        private readonly JobArtV2BattleHudService $battleHud,
        private readonly ?JobArtV2ProgressionService $progressionService = null,
    ) {
    }

    public function applyOnHit(
        BattleActor $attacker,
        BattleActor $target,
        BattleState $state,
        Skill $skill,
        ?HitResult $hitResult,
    ): JobArtV2SpPressureResult {
        if ($hitResult !== HitResult::HIT || ! $this->featureGate->usesResources($attacker)) {
            return JobArtV2SpPressureResult::unchanged();
        }

        $progression = $this->progressionService ?? app(JobArtV2ProgressionService::class);
        $super = $progression->superAimSpPressure($attacker, $skill);
        $crown = $attacker->currentJobId === 65
            && $this->prototypeCatalog->isTrustedCurrentJobArt(65, $skill)
            && ($attacker->jobArtOrigins[(int) $skill->id] ?? 'current') === 'current';
        if ($super === null && ! $crown) {
            return JobArtV2SpPressureResult::unchanged();
        }

        $metadata = $this->prototypeCatalog->artResourceMetadata($skill) ?? [];
        $rate = max(0.0, (float) ($super['rate'] ?? $metadata['sp_pressure_rate'] ?? 0.0));
        if ($rate <= 0.0) {
            return JobArtV2SpPressureResult::unchanged();
        }

        $sourceActionId = $state->currentSourceActionId();
        if ($sourceActionId === null) {
            return JobArtV2SpPressureResult::unchanged('missing_source_action_id');
        }
        if (! $state->claimSpPressureEvent($attacker, $target, $sourceActionId)) {
            return JobArtV2SpPressureResult::unchanged('duplicate_sp_pressure_event');
        }

        $battleCap = (int) ceil(max(0, $target->maxMp) * 0.15);
        $requested = (int) ceil(max(0, $target->maxMp) * $rate);
        $alreadyLost = $state->spPressureActualLoss($attacker, $target);
        $remainingBefore = max(0, $battleCap - $alreadyLost);
        $spBefore = max(0, $target->mp);
        $actualLoss = min($requested, $spBefore, $remainingBefore);
        $target->mp = max(0, $spBefore - $actualLoss);
        $state->addSpPressureActualLoss($attacker, $target, $actualLoss);

        $result = new JobArtV2SpPressureResult(
            applied: $actualLoss > 0,
            requested: $requested,
            spBefore: $spBefore,
            spAfter: $target->mp,
            actualLoss: $actualLoss,
            battleCap: $battleCap,
            remainingCap: max(0, $remainingBefore - $actualLoss),
            sourceActionId: $sourceActionId,
        );
        $state->recordSpPressureResult($result);
        $this->battleHud->recordSpPressure($attacker, $state, $result);
        if ($super !== null) {
            $progression->markSuperAimSpPressureUsed($attacker);
        }

        return $result;
    }
}
