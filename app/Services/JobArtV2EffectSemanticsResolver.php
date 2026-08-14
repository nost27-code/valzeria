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
        private readonly ?JobArtV2DeckRoleResolver $deckRoleResolver = null,
        private readonly ?JobArtV2CDesignCatalog $cDesignCatalog = null,
        private readonly ?JobArtV2CDesignEffectCatalog $cDesignEffectCatalog = null,
    ) {}

    public function suppressesLegacySelfBuff(BattleActor $actor, Skill $skill): bool
    {
        $roleCatalog = $this->roleEffectCatalog ?? app(JobArtV2RoleEffectCatalog::class);
        if ($this->techReplacementTemplate($actor, $skill) !== null) {
            return true;
        }
        if ((bool) ($this->cDesignMetadata($actor, $skill)['suppress_legacy_effect'] ?? false)) {
            return true;
        }
        if ($this->featureGate->usesResources($actor)
            && $this->allowsRoleMetadata($actor, $skill)
            && $roleCatalog->isPortable($skill)
            && $roleCatalog->suppressesLegacyEffect($skill)
        ) {
            return true;
        }

        $resolution = ($this->deckRoleResolver ?? app(JobArtV2DeckRoleResolver::class))
            ->resolveActor($actor);
        $sourceJobId = $resolution->active
            && in_array(
                $resolution->roleFor($skill),
                [JobArtV2DeckRole::MAIN, JobArtV2DeckRole::SECONDARY],
                true,
            )
            && $resolution->blockReasonFor($skill) === null
                ? (int) $skill->job_id
                : $actor->currentJobId;

        return (
            $this->featureGate->usesResources($actor)
            && $this->suppressesForJobAndRank($sourceJobId, (int) $skill->learn_rank)
            && ($actor->jobArtOrigins[(int) $skill->id] ?? 'current') === 'current'
        ) || (
            $resolution->active
            && in_array(
                $resolution->roleFor($skill),
                [JobArtV2DeckRole::MAIN, JobArtV2DeckRole::SECONDARY],
                true,
            )
            && $resolution->blockReasonFor($skill) === null
            && $this->suppressesForJobAndRank((int) $skill->job_id, (int) $skill->learn_rank)
            && $this->prototypeCatalog->isTrustedArtProfile($skill)
        );
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
        $techTemplate = $this->techReplacementTemplate($actor, $sourceSkill);
        if ($techTemplate !== null) {
            $executionSkill->effect_template = $techTemplate;
            $executionSkill->damage_type = JobArtEffectCatalog::damageType($techTemplate);

            return;
        }
        $cDesignMetadata = $this->cDesignMetadata($actor, $sourceSkill);
        if ((bool) ($cDesignMetadata['suppress_legacy_effect'] ?? false)) {
            $template = is_string($cDesignMetadata['replacement_template'] ?? null)
                ? (string) $cDesignMetadata['replacement_template']
                : 'V2_ROLE_EFFECT_ONLY';
            $executionSkill->effect_template = $template;
            $executionSkill->damage_type = JobArtEffectCatalog::damageType($template);

            return;
        }
        if ($this->allowsRoleMetadata($actor, $sourceSkill)
            && $roleCatalog->isPortable($sourceSkill)
            && $roleCatalog->suppressesLegacyEffect($sourceSkill)
        ) {
            $template = $roleCatalog->replacementTemplate($sourceSkill) ?? 'V2_ROLE_EFFECT_ONLY';
            $executionSkill->effect_template = $template;
            $executionSkill->damage_type = JobArtEffectCatalog::damageType($template);

            return;
        }

        // Direct damage remains on the master's existing damage category;
        // only the generic self-buff side effect is replaced by trusted v2.
        if ((int) $sourceSkill->job_id === 66) {
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

    private function allowsRoleMetadata(BattleActor $actor, Skill $skill): bool
    {
        $resolution = ($this->deckRoleResolver ?? app(JobArtV2DeckRoleResolver::class))
            ->resolveActor($actor);
        if (! $resolution->active) {
            return true;
        }

        if ($resolution->roleFor($skill) === JobArtV2DeckRole::TECH
            && $resolution->blockReasonFor($skill) === null
        ) {
            return ($this->cDesignCatalog ?? app(JobArtV2CDesignCatalog::class))
                ->allowsTechBaseRoleMetadata($skill);
        }

        return in_array(
            $resolution->roleFor($skill),
            [JobArtV2DeckRole::MAIN, JobArtV2DeckRole::SECONDARY],
            true,
        ) && $resolution->blockReasonFor($skill) === null;
    }

    private function techReplacementTemplate(BattleActor $actor, Skill $skill): ?string
    {
        $resolution = ($this->deckRoleResolver ?? app(JobArtV2DeckRoleResolver::class))
            ->resolveActor($actor);
        if (! $resolution->active
            || $resolution->roleFor($skill) !== JobArtV2DeckRole::TECH
            || $resolution->blockReasonFor($skill) !== null
        ) {
            return null;
        }

        return ($this->cDesignCatalog ?? app(JobArtV2CDesignCatalog::class))
            ->techReplacementTemplate($skill);
    }

    /** @return array<string, mixed>|null */
    private function cDesignMetadata(BattleActor $actor, Skill $skill): ?array
    {
        $resolution = ($this->deckRoleResolver ?? app(JobArtV2DeckRoleResolver::class))
            ->resolveActor($actor);
        if (! $resolution->active
            || ! in_array(
                $resolution->roleFor($skill),
                [JobArtV2DeckRole::MAIN, JobArtV2DeckRole::SECONDARY],
                true,
            )
            || $resolution->blockReasonFor($skill) !== null
        ) {
            return null;
        }

        return ($this->cDesignEffectCatalog ?? app(JobArtV2CDesignEffectCatalog::class))
            ->forArt($skill);
    }
}
