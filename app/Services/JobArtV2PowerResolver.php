<?php

namespace App\Services;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;

class JobArtV2PowerResolver
{
    public function __construct(
        private readonly JobArtV2FeatureGate $featureGate,
        private readonly JobArtV2PrototypeCatalog $prototypeCatalog,
        private readonly JobArtV2PowerCatalog $powerCatalog,
    ) {
    }

    public function forExecution(BattleActor $actor, Skill $skill, ?BattleState $state = null): int
    {
        $power = $this->resolve(
            $actor->currentJobId,
            $skill,
            (string) ($actor->jobArtOrigins[(int) $skill->id] ?? ''),
            $this->featureGate->usesResources($actor),
            $this->featureGate->usesPenetration($actor),
        );
        $branch = $this->fieldOverwriteBranchForExecution($actor, $skill, $state);

        return $branch === null
            ? $power
            : max(0, (int) round($power * $branch['multiplier']));
    }

    /**
     * @return array{
     *     overwrite_count: int,
     *     primary_field_present: bool,
     *     multiplier: float,
     *     bonus_percent: int
     * }|null
     */
    public function fieldOverwriteBranchForExecution(
        BattleActor $actor,
        Skill $skill,
        ?BattleState $state,
    ): ?array {
        $origin = (string) ($actor->jobArtOrigins[(int) $skill->id] ?? '');
        if ($state === null
            || $actor->currentJobId !== 63
            || (int) $skill->job_id !== 63
            || (int) $skill->learn_rank !== 9
            || $origin !== 'current'
            || ! $this->prototypeCatalog->isTrustedCurrentJobArt($actor->currentJobId, $skill)
            || ! $this->featureGate->usesFields($state)
            || $state->currentActionActorKey() !== $state->actorKey($actor)
        ) {
            return null;
        }

        $metadata = $this->prototypeCatalog->artResourceMetadata($skill) ?? [];
        $overwriteCount = $state->fieldOverwriteCountFor($actor);
        $primaryFieldPresent = $state->currentFieldSnapshot()->primary !== null;
        $multiplier = $primaryFieldPresent
            ? $this->fieldOverwriteMultiplier($metadata, $overwriteCount)
            : 1.0;

        return [
            'overwrite_count' => $overwriteCount,
            'primary_field_present' => $primaryFieldPresent,
            'multiplier' => $multiplier,
            'bonus_percent' => (int) round(($multiplier - 1.0) * 100),
        ];
    }

    public function forDisplay(?int $currentJobId, Skill $skill): int
    {
        if (! $this->featureGate->usesLoadoutUiForCurrentJob($currentJobId)) {
            return max(0, (int) $skill->power);
        }

        return $this->resolve(
            $currentJobId,
            $skill,
            (string) $skill->getAttribute('job_art_origin'),
            $this->featureGate->usesResourcesForCurrentJob($currentJobId),
            $this->featureGate->usesPenetrationForCurrentJob($currentJobId),
        );
    }

    private function resolve(
        ?int $currentJobId,
        Skill $skill,
        string $origin,
        bool $resourcesEnabled,
        bool $penetrationEnabled,
    ): int
    {
        $masterPower = max(0, (int) $skill->power);

        if ($origin !== 'current'
            || ! $this->prototypeCatalog->isTrustedCurrentJobArt($currentJobId, $skill)
            || ! $resourcesEnabled
        ) {
            return $masterPower;
        }

        $override = $this->powerCatalog->overrideFor($skill);
        if ($override === null) {
            return $masterPower;
        }

        if ($override['requires'] === 'penetration' && ! $penetrationEnabled) {
            return $masterPower;
        }

        return $override['power'];
    }

    /** @param array<string, int|float|string|bool> $metadata */
    private function fieldOverwriteMultiplier(array $metadata, int $overwriteCount): float
    {
        if (! (bool) ($metadata['field_overwrite_power_requires_primary'] ?? false)) {
            return 1.0;
        }

        return match (true) {
            $overwriteCount >= 5 => (float) ($metadata['field_overwrite_power_multiplier_5_plus'] ?? 1.0),
            $overwriteCount >= 3 => (float) ($metadata['field_overwrite_power_multiplier_3_4'] ?? 1.0),
            $overwriteCount >= 1 => (float) ($metadata['field_overwrite_power_multiplier_1_2'] ?? 1.0),
            default => 1.0,
        };
    }
}
