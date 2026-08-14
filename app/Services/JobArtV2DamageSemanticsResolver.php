<?php

namespace App\Services;

use App\Models\Skill;
use App\Services\Battle\BattleActor;

final class JobArtV2DamageSemanticsResolver
{
    public function __construct(
        private readonly JobArtV2FeatureGate $featureGate,
        private readonly JobArtV2PrototypeCatalog $prototypeCatalog,
        private readonly JobArtV2DamageSemanticsCatalog $semanticsCatalog,
        private readonly ?JobArtV2DeckRoleResolver $deckRoleResolver = null,
    ) {}

    /** @return array{attack_stat: string, defense_stat: string, damage_category: string}|null */
    public function forExecution(BattleActor $actor, Skill $skill): ?array
    {
        $resolution = ($this->deckRoleResolver ?? app(JobArtV2DeckRoleResolver::class))
            ->resolveActor($actor);
        if ($resolution->active) {
            return in_array(
                $resolution->roleFor($skill),
                [JobArtV2DeckRole::MAIN, JobArtV2DeckRole::SECONDARY],
                true,
            ) && $resolution->blockReasonFor($skill) === null
                && $this->prototypeCatalog->isTrustedArtProfile($skill)
                    ? $this->semanticsCatalog->overrideFor($skill)
                    : null;
        }

        return $this->resolve(
            $actor->currentJobId,
            $skill,
            (string) ($actor->jobArtOrigins[(int) $skill->id] ?? ''),
            $this->featureGate->usesResources($actor),
        );
    }

    /** @return array{attack_stat: string, defense_stat: string, damage_category: string}|null */
    public function forDisplay(?int $currentJobId, Skill $skill, bool $formalCDesignLineage = false): ?array
    {
        if (! $this->featureGate->usesLoadoutUiForCurrentJob($currentJobId)) {
            return null;
        }

        if ($formalCDesignLineage
            && $this->featureGate->usesCDesignPrototypeForCurrentJob($currentJobId)
            && $this->prototypeCatalog->isTrustedArtProfile($skill)
        ) {
            return $this->semanticsCatalog->overrideFor($skill);
        }

        return $this->resolve(
            $currentJobId,
            $skill,
            (string) $skill->getAttribute('job_art_origin'),
            $this->featureGate->usesResourcesForCurrentJob($currentJobId),
        );
    }

    public function applyForExecution(BattleActor $actor, Skill $sourceSkill, Skill $executionSkill): void
    {
        $semantics = $this->forExecution($actor, $sourceSkill);
        if ($semantics === null) {
            return;
        }

        $executionSkill->damage_type = $semantics['damage_category'];
        $executionSkill->effect_template = 'MAGICAL_DAMAGE';
        $executionSkill->drain_hp_rate = 0;
    }

    /** @return array{attack_stat: string, defense_stat: string, damage_category: string}|null */
    private function resolve(
        ?int $currentJobId,
        Skill $skill,
        string $origin,
        bool $resourcesEnabled,
    ): ?array {
        if (! $resourcesEnabled
            || $origin !== 'current'
            || ! $this->prototypeCatalog->isTrustedCurrentJobArt($currentJobId, $skill)
        ) {
            return null;
        }

        return $this->semanticsCatalog->overrideFor($skill);
    }
}
