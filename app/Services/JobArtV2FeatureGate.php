<?php

namespace App\Services;

use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;

class JobArtV2FeatureGate
{
    public function __construct(
        private readonly JobArtV2PrototypeCatalog $catalog,
    ) {
    }

    public function usesDynamicSingle(BattleActor $actor): bool
    {
        return (bool) config('battle.job_art_v2.dynamic_single', false)
            && $this->catalog->supportsCurrentJob($actor->currentJobId);
    }

    public function usesLoadoutUiForCurrentJob(?int $jobId): bool
    {
        return (bool) config('battle.job_art_v2.loadout_v2', false)
            && $this->catalog->supportsCurrentJob($jobId);
    }

    public function usesPresetsForCurrentJob(?int $jobId): bool
    {
        return (bool) config('battle.job_art_v2.loadout_v2', false)
            && (bool) config('battle.job_art_v2.presets', false)
            && $this->catalog->supportsCurrentJob($jobId);
    }

    public function usesLoadoutRestrictionCompatibilityForCurrentJob(?int $jobId): bool
    {
        return (bool) config('battle.job_art_v2.loadout_v2', false)
            && (bool) config('battle.job_art_v2.dynamic_single', false)
            && $this->catalog->supportsCurrentJob($jobId);
    }

    public function usesPr5Rules(BattleActor $actor): bool
    {
        return $this->usesPr5RulesForCurrentJob($actor->currentJobId);
    }

    public function usesPr5RulesForCurrentJob(?int $jobId): bool
    {
        return (bool) config('battle.job_art_v2.dynamic_single', false)
            && (bool) config('battle.job_art_v2.normalized_sp', false)
            && $this->catalog->supportsCurrentJob($jobId);
    }

    public function usesHitResolution(BattleActor $actor): bool
    {
        return $this->usesHitResolutionForCurrentJob($actor->currentJobId);
    }

    public function usesHitResolutionForCurrentJob(?int $jobId): bool
    {
        return (bool) config('battle.job_art_v2.dynamic_single', false)
            && (bool) config('battle.job_art_v2.hit_resolution', false)
            && $this->catalog->supportsCurrentJob($jobId);
    }

    public function usesDamageApplication(?BattleActor $source, BattleActor $target): bool
    {
        return (bool) config('battle.job_art_v2.dynamic_single', false)
            && (bool) config('battle.job_art_v2.hit_resolution', false)
            && (bool) config('battle.job_art_v2.damage_application', false)
            && ($this->catalog->supportsCurrentJob($source?->currentJobId)
                || $this->catalog->supportsCurrentJob($target->currentJobId));
    }

    public function usesResources(BattleActor $actor): bool
    {
        return (bool) config('battle.job_art_v2.dynamic_single', false)
            && (bool) config('battle.job_art_v2.hit_resolution', false)
            && (bool) config('battle.job_art_v2.damage_application', false)
            && (bool) config('battle.job_art_v2.resources', false)
            && $this->catalog->supportsCurrentJob($actor->currentJobId);
    }

    public function usesFields(BattleState $state): bool
    {
        return (bool) config('battle.job_art_v2.dynamic_single', false)
            && (bool) config('battle.job_art_v2.hit_resolution', false)
            && (bool) config('battle.job_art_v2.damage_application', false)
            && (bool) config('battle.job_art_v2.resources', false)
            && (bool) config('battle.job_art_v2.fields', false)
            && ($this->catalog->supportsLineageCapability($state->player->currentJobId, 'field')
                || $this->catalog->supportsLineageCapability($state->enemy->currentJobId, 'field'));
    }

    public function usesResourcesForCurrentJob(?int $jobId): bool
    {
        return (bool) config('battle.job_art_v2.dynamic_single', false)
            && (bool) config('battle.job_art_v2.hit_resolution', false)
            && (bool) config('battle.job_art_v2.damage_application', false)
            && (bool) config('battle.job_art_v2.resources', false)
            && $this->catalog->supportsCurrentJob($jobId);
    }

    public function usesFieldsForCurrentJob(?int $jobId): bool
    {
        return $this->usesResourcesForCurrentJob($jobId)
            && (bool) config('battle.job_art_v2.fields', false)
            && $this->catalog->supportsLineageCapability($jobId, 'field');
    }

    public function usesPenetration(BattleActor $actor): bool
    {
        return $this->usesResources($actor)
            && (bool) config('battle.job_art_v2.penetration', false)
            && $this->catalog->supportsLineageCapability($actor->currentJobId, 'penetration');
    }

    public function usesPenetrationForCurrentJob(?int $jobId): bool
    {
        return $this->usesResourcesForCurrentJob($jobId)
            && (bool) config('battle.job_art_v2.penetration', false)
            && $this->catalog->supportsLineageCapability($jobId, 'penetration');
    }

    public function usesPenetrationStance(BattleActor $actor): bool
    {
        return $this->usesPenetration($actor)
            && (bool) config('battle.job_art_v2.penetration_stance', false)
            && $this->catalog->supportsLineageCapability($actor->currentJobId, 'penetration_stance');
    }

    public function usesPenetrationStanceForCurrentJob(?int $jobId): bool
    {
        return $this->usesPenetrationForCurrentJob($jobId)
            && (bool) config('battle.job_art_v2.penetration_stance', false)
            && $this->catalog->supportsLineageCapability($jobId, 'penetration_stance');
    }
}
