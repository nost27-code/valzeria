<?php

namespace App\Services;

use App\Models\Skill;
use App\Services\Battle\BattleActor;

class JobArtV2BattleRules
{
    private const ACTIVATION_RATES = [
        1 => 35,
        5 => 38,
        9 => 50,
    ];

    public function __construct(
        private readonly JobArtV2FeatureGate $featureGate,
    ) {
    }

    public function activationRateFor(Skill $skill, ?int $currentJobId, ?string $origin = null): int
    {
        $origin ??= (string) $skill->getAttribute('job_art_origin');
        if ($origin === '') {
            $sourceJobId = $skill->getAttribute('job_id');
            $origin = $sourceJobId !== null
                && $currentJobId !== null
                && (int) $sourceJobId === $currentJobId
                    ? 'current'
                    : 'inherited';
        }

        if (!$this->featureGate->usesPr5RulesForCurrentJob($currentJobId)) {
            return $skill->effectiveActivationRate();
        }

        return self::ACTIVATION_RATES[(int) $skill->learn_rank]
            ?? $skill->effectiveActivationRate();
    }

    public function conserveThresholdFor(BattleActor $actor): float
    {
        return 0.60;
    }

    public function conserveThresholdPercentForCurrentJob(?int $currentJobId): int
    {
        return 60;
    }
}
