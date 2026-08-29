<?php

namespace App\Services;

use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;

class JobArtV2FeatureGate
{
    public function __construct(
        private readonly JobArtV2PrototypeCatalog $catalog,
        private readonly ?JobArtV2DeckRoleResolver $deckRoleResolver = null,
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

    public function usesRank5V6(BattleActor $actor): bool
    {
        return (bool) config('battle.job_art_v2.rank5_v6', false)
            && $this->usesResources($actor);
    }

    public function usesRank5V6ForCurrentJob(?int $jobId): bool
    {
        return (bool) config('battle.job_art_v2.rank5_v6', false)
            && $this->usesResourcesForCurrentJob($jobId);
    }

    public function usesDetailedStrategy(BattleActor $actor): bool
    {
        return (bool) config('battle.job_art_v2.detailed_strategy', false)
            && $this->usesResources($actor);
    }

    public function usesDetailedStrategyForCurrentJob(?int $jobId): bool
    {
        return (bool) config('battle.job_art_v2.detailed_strategy', false)
            && $this->usesResourcesForCurrentJob($jobId);
    }

    /**
     * Config-only half of the SP-output gate, shared by runtime actors and UI
     * previews. Current-job support is checked by the caller that has a job.
     */
    public function spPowerScalingConfigurationEnabled(string $context = 'normal'): bool
    {
        if (! (bool) config('battle.job_art_v2.sp_power_scaling.enabled', false)
            || ! (bool) config('battle.job_art_v2.dynamic_single', false)
            || ! (bool) config('battle.job_art_v2.hit_resolution', false)
            || ! (bool) config('battle.job_art_v2.damage_application', false)
            || ! (bool) config('battle.job_art_v2.resources', false)
            || ! (bool) config('battle.job_art_v2.rank5_v6', false)
        ) {
            return false;
        }

        if (in_array($context, ['pvp', 'arena_npc', 'champ'], true)
            && ! (bool) config('battle.job_art_v2.pvp_set', false)
        ) {
            return false;
        }

        return $context !== 'champ'
            || (bool) config('battle.job_art_v2.sp_power_scaling.champ_enabled', false);
    }

    /**
     * Fixed-cost + variable-output scaling is a fail-closed extension of the
     * complete v2 damage chain. PvP settings and Champ have additional gates.
     */
    public function usesSpPowerScaling(BattleActor $actor): bool
    {
        if (! $this->spPowerScalingConfigurationEnabled($actor->spPowerScalingContext)
            || ! $this->catalog->supportsCurrentJob($actor->currentJobId)
            || ! $actor->spScalingEligible
        ) {
            return false;
        }

        return true;
    }

    public function usesSpPowerScalingForCurrentJob(?int $jobId, string $context = 'normal'): bool
    {
        return $this->spPowerScalingConfigurationEnabled($context)
            && $this->catalog->supportsCurrentJob($jobId);
    }

    public function usesFields(BattleState $state): bool
    {
        return (bool) config('battle.job_art_v2.dynamic_single', false)
            && (bool) config('battle.job_art_v2.hit_resolution', false)
            && (bool) config('battle.job_art_v2.damage_application', false)
            && (bool) config('battle.job_art_v2.resources', false)
            && (bool) config('battle.job_art_v2.fields', false)
            && ($this->catalog->supportsCurrentJob($state->player->currentJobId)
                || $this->catalog->supportsCurrentJob($state->enemy->currentJobId));
    }

    public function usesResourcesForCurrentJob(?int $jobId): bool
    {
        return (bool) config('battle.job_art_v2.dynamic_single', false)
            && (bool) config('battle.job_art_v2.hit_resolution', false)
            && (bool) config('battle.job_art_v2.damage_application', false)
            && (bool) config('battle.job_art_v2.resources', false)
            && $this->catalog->supportsCurrentJob($jobId);
    }

    public function usesCDesignPrototype(BattleActor $actor): bool
    {
        return $this->usesResources($actor)
            && $this->roles()->resolveActor($actor)->active;
    }

    public function usesCDesignPrototypeForCurrentJob(?int $jobId): bool
    {
        return $this->usesResourcesForCurrentJob($jobId)
            && $this->roles()->supportsCurrentJob($jobId);
    }

    public function usesUltimateCounterplay(BattleState $state): bool
    {
        return (bool) config('battle.job_art_v2.ultimate_counterplay', false)
            && in_array($state->battleType, ['pvp', 'champ', 'arena_npc'], true)
            && $this->usesCDesignPrototype($state->player)
            && $this->usesCDesignPrototype($state->enemy);
    }

    public function usesFieldsForCurrentJob(?int $jobId): bool
    {
        return $this->usesResourcesForCurrentJob($jobId)
            && (bool) config('battle.job_art_v2.fields', false);
    }

    public function usesPenetration(BattleActor $actor): bool
    {
        return $this->usesResources($actor)
            && (bool) config('battle.job_art_v2.penetration', false)
            && ($this->catalog->supportsLineageCapability($actor->currentJobId, 'penetration')
                || ($this->usesCDesignPrototype($actor)
                    && $this->roles()->hasFormalLineage($actor, 'pierce')));
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
            && ($this->catalog->supportsLineageCapability($actor->currentJobId, 'penetration_stance')
                || ($this->usesCDesignPrototype($actor)
                    && $this->roles()->hasFormalLineage($actor, 'pierce')));
    }

    public function usesPenetrationStanceForCurrentJob(?int $jobId): bool
    {
        return $this->usesPenetrationForCurrentJob($jobId)
            && (bool) config('battle.job_art_v2.penetration_stance', false)
            && $this->catalog->supportsLineageCapability($jobId, 'penetration_stance');
    }

    private function roles(): JobArtV2DeckRoleResolver
    {
        return $this->deckRoleResolver ?? app(JobArtV2DeckRoleResolver::class);
    }
}
