<?php

namespace App\Services;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;

final class JobArtV2ConversionService
{
    public const BLOCKED_BY_HP = 'blocked_by_conversion_hp';
    public const BLOCKED_BY_SP_GAIN = 'blocked_by_conversion_sp_gain';

    private const RANK = 1;
    private const RATE = 0.05;

    public function __construct(
        private readonly JobArtV2FeatureGate $featureGate,
        private readonly JobArtV2PrototypeCatalog $prototypeCatalog,
        private readonly JobArtV2SpCostCalculator $spCostCalculator,
        private readonly ?JobArtV2DeckRoleResolver $deckRoleResolver = null,
    ) {}

    public function eligibilityBlockReason(BattleActor $actor, Skill $skill): ?string
    {
        if (! $this->appliesTo($actor, $skill)) {
            return null;
        }

        $requestedHpCost = $this->requestedHpCost($actor);
        if ($requestedHpCost < 1 || $actor->hp - $requestedHpCost < 1) {
            return self::BLOCKED_BY_HP;
        }

        $spAfterArtCost = $actor->mp - $this->spCostCalculator->forActor($actor, $skill);
        $possibleGain = min(
            $this->requestedSpGain($actor),
            max(0, $actor->maxMp - $spAfterArtCost),
        );

        return $possibleGain >= 1 ? null : self::BLOCKED_BY_SP_GAIN;
    }

    /**
     * Rank1の正式SP消費後に呼ぶ。HP消費はdamage/self-damageとして通知しない。
     */
    public function applyAfterArtSpCost(
        BattleActor $actor,
        BattleState $state,
        Skill $skill,
    ): ?ConversionResult {
        if (! $this->appliesTo($actor, $skill)) {
            return null;
        }

        $sourceActionId = $state->currentSourceActionId();
        if ($sourceActionId === null || ! $state->claimConversionAction($actor, $sourceActionId)) {
            return null;
        }

        $hpBefore = $actor->hp;
        $spBefore = $actor->mp;
        $requestedHpCost = $this->requestedHpCost($actor);
        $requestedSpGain = $this->requestedSpGain($actor);
        $canPayHp = $requestedHpCost >= 1 && $hpBefore - $requestedHpCost >= 1;
        $possibleSpGain = min($requestedSpGain, max(0, $actor->maxMp - $spBefore));
        $canConvert = $canPayHp && $possibleSpGain >= 1;

        if ($canConvert) {
            $actor->hp -= $requestedHpCost;
            $actor->mp = min($actor->maxMp, $actor->mp + $requestedSpGain);
        }

        $result = new ConversionResult(
            sourceActionId: $sourceActionId,
            actorKey: $state->actorKey($actor),
            hpBefore: $hpBefore,
            requestedHpCost: $requestedHpCost,
            actualHpLoss: $hpBefore - $actor->hp,
            hpAfter: $actor->hp,
            spBeforeConversion: $spBefore,
            requestedSpGain: $requestedSpGain,
            actualSpGain: $actor->mp - $spBefore,
            spAfterConversion: $actor->mp,
            success: $canConvert
                && $hpBefore - $actor->hp >= 1
                && $actor->mp - $spBefore >= 1,
        );
        $state->recordConversionResult($result);

        if ($result->success) {
            $state->addLog(sprintf(
                '<span class="text-emerald-700 font-bold">変成：HP %s→%s / SP %s→%s</span>',
                number_format($result->hpBefore),
                number_format($result->hpAfter),
                number_format($result->spBeforeConversion),
                number_format($result->spAfterConversion),
            ));
        }

        return $result;
    }

    private function appliesTo(BattleActor $actor, Skill $skill): bool
    {
        $metadata = $this->prototypeCatalog->artResourceMetadata($skill);
        $resolution = ($this->deckRoleResolver ?? app(JobArtV2DeckRoleResolver::class))
            ->resolveActor($actor);
        $trusted = $resolution->active
            ? in_array(
                $resolution->roleFor($skill),
                [JobArtV2DeckRole::MAIN, JobArtV2DeckRole::SECONDARY],
                true,
            ) && $resolution->blockReasonFor($skill) === null
                && $this->prototypeCatalog->isTrustedArtProfile($skill)
            : ($actor->jobArtOrigins[(int) $skill->id] ?? 'current') === 'current'
                && $this->prototypeCatalog->isTrustedCurrentJobArt($actor->currentJobId, $skill);

        return $this->featureGate->usesResources($actor)
            && (int) $skill->learn_rank === self::RANK
            && $trusted
            && ($metadata['lineage_key'] ?? null) === 'transmute'
            && ($metadata['resource_gain_event'] ?? null) === ResourceEvent::HP_SP_CONVERSION_SUCCESS->value;
    }

    private function requestedHpCost(BattleActor $actor): int
    {
        return (int) ceil(max(0, $actor->maxHp) * self::RATE);
    }

    private function requestedSpGain(BattleActor $actor): int
    {
        return (int) ceil(max(0, $actor->maxMp) * self::RATE);
    }
}
