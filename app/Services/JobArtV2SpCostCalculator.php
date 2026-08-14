<?php

namespace App\Services;

use App\Models\Character;
use App\Models\Skill;
use App\Services\Battle\BattleActor;

class JobArtV2SpCostCalculator
{
    /** @var array<string, array<int, int>> */
    private const FIXED_COSTS = [
        'basic' => [1 => 4, 5 => 6, 9 => 8],
        'intermediate' => [1 => 6, 5 => 9, 9 => 13],
        'advanced' => [1 => 10, 5 => 16, 9 => 22],
        'super' => [1 => 16, 5 => 25, 9 => 35],
        'crown' => [1 => 23, 5 => 36, 9 => 50],
        'hero' => [1 => 30, 5 => 48, 9 => 66],
        'legend' => [1 => 40, 5 => 64, 9 => 88],
        'myth' => [1 => 52, 5 => 84, 9 => 115],
    ];

    public function __construct(
        private readonly JobArtV2FeatureGate $featureGate,
        private readonly JobArtV2PrototypeCatalog $prototypeCatalog,
        private readonly ?JobArtV2ProgressionService $progressionService = null,
    ) {
    }

    public function forActor(BattleActor $actor, Skill $skill): int
    {
        $legacyOrigin = (string) ($actor->jobArtOrigins[(int) $skill->id] ?? 'current');

        $base = $this->forCurrentJob(
            $skill,
            $actor->maxMp,
            $actor->currentJobId,
            $legacyOrigin,
        );

        return ($this->progressionService ?? app(JobArtV2ProgressionService::class))
            ->adjustedSpCost($actor, $skill, $base);
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
        $tier = $this->prototypeCatalog->currentJobTier(
            $skill->job_id !== null ? (int) $skill->job_id : null,
        );
        $fixed = $tier !== null
            ? (self::FIXED_COSTS[$tier][(int) $skill->learn_rank] ?? null)
            : null;
        if ($fixed === null) {
            throw new \LogicException(sprintf(
                '戦技SP固定表に未登録の職またはRankです: job_id=%s, learn_rank=%s',
                $skill->job_id === null ? 'null' : (string) $skill->job_id,
                $skill->learn_rank === null ? 'null' : (string) $skill->learn_rank,
            ));
        }

        // 戦技のSPは「覚える職の階級×始動/連携/奥義」で固定する。
        // 最大SP、現在職、同系譜、旧継承率では増減しない。
        return $fixed;
    }
}
