<?php

namespace App\Services;

use App\Models\Skill;
use App\Services\Battle\BattleActor;

class JobArtV2ResourceCatalog
{
    public function __construct(
        private readonly JobArtV2PrototypeCatalog $prototypeCatalog,
    ) {
    }

    /** @return array{lineage_key: string, resource_key: string, resource_name: string, resource_max_points: int}|null */
    public function forActor(BattleActor $actor): ?array
    {
        return $this->prototypeCatalog->jobResourceMetadata($actor->currentJobId);
    }

    /** @return array{lineage_key: string, resource_key: string, resource_name: string, resource_max_points: int}|null */
    public function forCurrentJob(?int $jobId): ?array
    {
        return $this->prototypeCatalog->jobResourceMetadata($jobId);
    }

    /** @return array<string, int|string|bool>|null */
    public function forArt(Skill $skill): ?array
    {
        return $this->prototypeCatalog->artResourceMetadata($skill);
    }

    public function roleForArt(Skill $skill): ?ResourceRole
    {
        $role = $this->forArt($skill)['resource_role'] ?? null;

        return is_string($role) ? ResourceRole::tryFrom($role) : null;
    }

    public function roleForActorArt(BattleActor $actor, Skill $skill): ?ResourceRole
    {
        $role = $this->forActorArt($actor, $skill)['resource_role'] ?? null;

        return is_string($role) ? ResourceRole::tryFrom($role) : null;
    }

    /** @return array<string, int|float|string|bool>|null */
    public function forActorArt(BattleActor $actor, Skill $skill): ?array
    {
        return $this->forCurrentJobArt(
            $actor->currentJobId,
            $skill,
            $this->originFor($actor, $skill),
        );
    }

    /** @return array<string, int|float|string|bool>|null */
    public function forCurrentJobArt(?int $currentJobId, Skill $skill, string $origin): ?array
    {
        $actorMetadata = $this->forCurrentJob($currentJobId);
        $artMetadata = $this->forArt($skill);
        if ($actorMetadata === null
            || $artMetadata === null
            || ! $this->prototypeCatalog->isTrustedArtProfile($skill)
            || $actorMetadata['lineage_key'] !== $artMetadata['lineage_key']
        ) {
            return null;
        }

        if ($origin === 'current'
            && ! $this->prototypeCatalog->isTrustedCurrentJobArt($currentJobId, $skill)
        ) {
            return null;
        }
        if (! in_array($origin, ['current', 'inherited'], true)) {
            return null;
        }

        $portableMetadata = $artMetadata;
        if ($origin === 'inherited'
            && ($portableMetadata['resource_role'] ?? null) === ResourceRole::PRODUCER->value
            && ($portableMetadata['resource_gain_event'] ?? null) === ResourceEvent::HP_SP_CONVERSION_SUCCESS->value
        ) {
            // 67のHP→SP変換はcurrent-job限定。継承時はproducerの+4だけを共有resourceへ載せる。
            $portableMetadata['resource_gain_event'] = ResourceEvent::JOB_ART_CAST->value;
        }

        return array_merge($portableMetadata, [
            'lineage_key' => $actorMetadata['lineage_key'],
            'resource_key' => $actorMetadata['resource_key'],
            'resource_name' => $actorMetadata['resource_name'],
            'resource_max_points' => $actorMetadata['resource_max_points'],
            'resource_origin' => $origin === 'current' ? 'current' : 'same_lineage_inherited',
        ]);
    }

    public function usesPrimaryResource(BattleActor $actor, Skill $skill): bool
    {
        return $this->forActorArt($actor, $skill) !== null;
    }

    public function isInheritedOrigin(BattleActor $actor, Skill $skill): bool
    {
        $origin = (string) ($actor->jobArtOrigins[(int) $skill->id]
            ?? ((int) $skill->job_id === (int) $actor->currentJobId ? 'current' : 'inherited'));

        return $origin === 'inherited';
    }

    public function isTrustedCurrentJobOrigin(BattleActor $actor, Skill $skill): bool
    {
        $origin = (string) ($actor->jobArtOrigins[(int) $skill->id]
            ?? ((int) $skill->job_id === (int) $actor->currentJobId ? 'current' : 'inherited'));

        return $origin === 'current'
            && $this->prototypeCatalog->isTrustedCurrentJobArt($actor->currentJobId, $skill);
    }

    public function isSameLineageInherited(BattleActor $actor, Skill $skill): bool
    {
        return $this->isInheritedOrigin($actor, $skill)
            && $this->forActorArt($actor, $skill) !== null;
    }

    private function originFor(BattleActor $actor, Skill $skill): string
    {
        return (string) ($actor->jobArtOrigins[(int) $skill->id]
            ?? ((int) $skill->job_id === (int) $actor->currentJobId ? 'current' : 'inherited'));
    }
}
