<?php

namespace App\Services;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Support\JobArtEffectCatalog;

/** Runtime-only replacement for legacy template side effects. */
final class JobArtV2EffectSemanticsResolver
{
    public function __construct(
        private readonly JobArtV2FeatureGate $featureGate,
        private readonly JobArtV2PrototypeCatalog $prototypeCatalog,
        private readonly ?JobArtV2RoleEffectCatalog $roleEffectCatalog = null,
    ) {}

    public function suppressesLegacySelfBuff(BattleActor $actor, Skill $skill): bool
    {
        $roleCatalog = $this->roleEffectCatalog ?? app(JobArtV2RoleEffectCatalog::class);
        if ($this->featureGate->usesResources($actor)
            && $roleCatalog->isPortable($skill)
            && $roleCatalog->suppressesLegacyEffect($skill)
        ) {
            return true;
        }

        return $this->featureGate->usesResources($actor)
            && $this->suppressesForJobAndRank($actor->currentJobId, (int) $skill->learn_rank)
            && ($actor->jobArtOrigins[(int) $skill->id] ?? 'current') === 'current'
            && $this->prototypeCatalog->isTrustedCurrentJobArt($actor->currentJobId, $skill);
    }

    public function replacementEffectTemplateForDisplay(?int $currentJobId, Skill $skill): ?string
    {
        $origin = (string) $skill->getAttribute('job_art_origin');
        $roleCatalog = $this->roleEffectCatalog ?? app(JobArtV2RoleEffectCatalog::class);

        if ($this->featureGate->usesResourcesForCurrentJob($currentJobId)
            && in_array($origin, ['', 'current', 'inherited'], true)
            && $roleCatalog->isPortable($skill)
            && $roleCatalog->suppressesLegacyEffect($skill)
        ) {
            return $roleCatalog->replacementTemplate($skill) ?? 'V2_ROLE_EFFECT_ONLY';
        }

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

        $roleCatalog = $this->roleEffectCatalog ?? app(JobArtV2RoleEffectCatalog::class);
        if ($roleCatalog->isPortable($sourceSkill) && $roleCatalog->suppressesLegacyEffect($sourceSkill)) {
            $template = $roleCatalog->replacementTemplate($sourceSkill) ?? 'V2_ROLE_EFFECT_ONLY';
            $executionSkill->effect_template = $template;
            $executionSkill->damage_type = JobArtEffectCatalog::damageType($template);

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
