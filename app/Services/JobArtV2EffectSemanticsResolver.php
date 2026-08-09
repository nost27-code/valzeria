<?php

namespace App\Services;

use App\Models\Skill;
use App\Services\Battle\BattleActor;

/** Runtime-only replacement for legacy template side effects. */
final class JobArtV2EffectSemanticsResolver
{
    public function __construct(
        private readonly JobArtV2FeatureGate $featureGate,
        private readonly JobArtV2PrototypeCatalog $prototypeCatalog,
    ) {}

    public function suppressesLegacySelfBuff(BattleActor $actor, Skill $skill): bool
    {
        return $this->featureGate->usesResources($actor)
            && $this->suppressesForJobAndRank($actor->currentJobId, (int) $skill->learn_rank)
            && ($actor->jobArtOrigins[(int) $skill->id] ?? 'current') === 'current'
            && $this->prototypeCatalog->isTrustedCurrentJobArt($actor->currentJobId, $skill);
    }

    public function replacementEffectTemplateForDisplay(?int $currentJobId, Skill $skill): ?string
    {
        $origin = (string) $skill->getAttribute('job_art_origin');

        if (! $this->featureGate->usesResourcesForCurrentJob($currentJobId)
            || ! $this->suppressesForJobAndRank($currentJobId, (int) $skill->learn_rank)
            || ! in_array($origin, ['', 'current'], true)
            || ! $this->prototypeCatalog->isTrustedCurrentJobArt($currentJobId, $skill)
        ) {
            return null;
        }

        return $currentJobId === 66 ? 'MAGICAL_DAMAGE' : 'PHYSICAL_DAMAGE';
    }

    public function applyForExecution(BattleActor $actor, Skill $sourceSkill, Skill $executionSkill): void
    {
        if (! $this->suppressesLegacySelfBuff($actor, $sourceSkill)) {
            return;
        }

        // Direct damage remains on the master's existing damage category;
        // only the generic self-buff side effect is replaced by trusted v2.
        if ($actor->currentJobId === 66) {
            $executionSkill->effect_template = 'MAGICAL_DAMAGE';
            $executionSkill->damage_type = 'magical';
        } else {
            $executionSkill->effect_template = 'PHYSICAL_DAMAGE';
            $executionSkill->damage_type = 'physical';
        }
    }

    private function suppressesForJobAndRank(?int $currentJobId, int $rank): bool
    {
        return in_array($rank, match ($currentJobId) {
            66 => [1, 5, 9],
            68 => [5, 9],
            default => [],
        }, true);
    }
}
