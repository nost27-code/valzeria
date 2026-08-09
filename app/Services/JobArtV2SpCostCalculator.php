<?php

namespace App\Services;

use App\Models\Character;
use App\Models\Skill;
use App\Services\Battle\BattleActor;

class JobArtV2SpCostCalculator
{
    private const DENOMINATOR = 2000;
    private const CURRENT_JOB_DENOMINATOR = 2500;

    /** @var array<int, array{pure_rate: int, hybrid_fixed: int, hybrid_rate: int}> */
    private const RANK_FORMULAS = [
        1 => ['pure_rate' => 50, 'hybrid_fixed' => 4000, 'hybrid_rate' => 40],
        5 => ['pure_rate' => 80, 'hybrid_fixed' => 6000, 'hybrid_rate' => 65],
        9 => ['pure_rate' => 110, 'hybrid_fixed' => 8000, 'hybrid_rate' => 90],
    ];

    public function __construct(
        private readonly JobArtV2FeatureGate $featureGate,
    ) {
    }

    public function forActor(BattleActor $actor, Skill $skill): int
    {
        $legacyOrigin = (string) ($actor->jobArtOrigins[(int) $skill->id] ?? 'current');

        return $this->forCurrentJob(
            $skill,
            $actor->maxMp,
            $actor->currentJobId,
            $legacyOrigin,
        );
    }

    public function forCharacter(Character $character, Skill $skill, int $maxSp): int
    {
        $legacyOrigin = (string) ($skill->getAttribute('job_art_origin') ?: 'inherited');
        $currentJobId = $character->current_job_id !== null
            ? (int) $character->current_job_id
            : null;

        return $this->forCurrentJob($skill, $maxSp, $currentJobId, $legacyOrigin);
    }

    public function forCurrentJob(
        Skill $skill,
        int $maxSp,
        ?int $currentJobId,
        string $legacyOrigin = 'inherited',
    ): int
    {
        if (!$this->featureGate->usesPr5RulesForCurrentJob($currentJobId)) {
            return $skill->jobArtSpCostForMaxSp($maxSp, $legacyOrigin);
        }

        $formula = self::RANK_FORMULAS[(int) $skill->learn_rank] ?? null;
        if ($formula === null) {
            return $skill->jobArtSpCostForMaxSp($maxSp, $legacyOrigin);
        }

        $maxSp = max(0, $maxSp);
        $pureNumerator = $maxSp * $formula['pure_rate'];
        $hybridNumerator = $formula['hybrid_fixed'] + ($maxSp * $formula['hybrid_rate']);
        $baseNumerator = min($pureNumerator, $hybridNumerator);
        $sourceJobId = $skill->getAttribute('job_id');
        $isCurrentJobArt = $sourceJobId !== null
            && $currentJobId !== null
            && (int) $sourceJobId === $currentJobId;
        $denominator = $isCurrentJobArt
            ? self::CURRENT_JOB_DENOMINATOR
            : self::DENOMINATOR;

        return max(1, $this->ceilDiv($baseNumerator, $denominator));
    }

    private function ceilDiv(int $numerator, int $denominator): int
    {
        if ($numerator <= 0) {
            return 0;
        }

        return intdiv($numerator + $denominator - 1, $denominator);
    }
}
