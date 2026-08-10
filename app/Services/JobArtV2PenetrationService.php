<?php

namespace App\Services;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;

class JobArtV2PenetrationService
{
    public const MAX_PENETRATION_RATE = 0.50;

    public function __construct(
        private readonly JobArtV2FeatureGate $featureGate,
        private readonly JobArtV2PrototypeCatalog $catalog,
        private readonly ?JobArtV2ProgressionService $progressionService = null,
    ) {}

    public function enabledFor(BattleActor $actor): bool
    {
        return $this->featureGate->usesPenetration($actor);
    }

    public function trustedRateFor(BattleActor $actor, Skill $skill): ?float
    {
        if (! $this->enabledFor($actor)
            || ($actor->jobArtOrigins[(int) $skill->id] ?? null) !== 'current'
            || ! $this->catalog->isTrustedCurrentJobArt($actor->currentJobId, $skill)
        ) {
            return null;
        }

        $metadata = $this->catalog->artPenetrationMetadata($skill);
        if ($metadata === null) {
            return null;
        }

        return min(
            self::MAX_PENETRATION_RATE,
            max(0.0, (float) $metadata['penetration_rate']),
        );
    }

    /** @return array{def: ?int, spr: ?int, penetration_rate: ?float} */
    public function defenseOverrides(BattleActor $attacker, BattleActor $defender, Skill $skill): array
    {
        $superRate = ($this->progressionService ?? app(JobArtV2ProgressionService::class))
            ->superPierceRateFor($attacker, $skill);
        if ($superRate !== null) {
            return $superRate > 0.0
                ? ['def' => (int) floor($defender->effectiveDef() * (1 - $superRate)), 'spr' => null, 'penetration_rate' => $superRate]
                : ['def' => null, 'spr' => null, 'penetration_rate' => null];
        }

        $trustedRate = $this->trustedRateFor($attacker, $skill);
        if ($trustedRate !== null) {
            $existingRate = max(0.0, (int) $skill->def_ignore_percent / 100);
            $rate = min(self::MAX_PENETRATION_RATE, max($trustedRate, $existingRate));

            return [
                'def' => (int) floor($defender->effectiveDef() * (1 - $rate)),
                'spr' => null,
                'penetration_rate' => $rate,
            ];
        }

        if ((int) $skill->def_ignore_percent > 0) {
            $legacyRate = 1 - ((int) $skill->def_ignore_percent / 100);

            return [
                'def' => (int) floor($defender->def * $legacyRate),
                'spr' => (int) floor($defender->spr * $legacyRate),
                'penetration_rate' => null,
            ];
        }

        return ['def' => null, 'spr' => null, 'penetration_rate' => null];
    }
}
