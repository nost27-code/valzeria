<?php

namespace App\Services;

use App\Models\Skill;
use App\Services\Battle\BattleActor;

class JobArtV2ResourceCatalog
{
    public function __construct(
        private readonly JobArtV2PrototypeCatalog $prototypeCatalog,
        private readonly ?JobArtV2DeckRoleResolver $deckRoleResolver = null,
    ) {
    }

    /** @return array{lineage_key: string, resource_key: string, resource_name: string, resource_max_points: int}|null */
    public function forActor(BattleActor $actor): ?array
    {
        foreach ($actor->jobArts as $skill) {
            if ($skill instanceof Skill && (int) $skill->learn_rank === 9) {
                $resource = $this->forActorArt($actor, $skill);
                if ($resource !== null) {
                    return $resource;
                }
            }
        }

        return $this->resourcesForActor($actor)[0] ?? null;
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
        $metadata = $this->forCurrentJobArt(
            $actor->currentJobId,
            $skill,
            $this->originFor($actor, $skill),
        );
        $resolution = $this->roles()->resolveActor($actor);
        if ($metadata === null
            || ($resolution->active && $resolution->blockReasonFor($skill) !== null)
        ) {
            return null;
        }

        $metadata['resource_origin'] = 'equipped_lineage';
        $metadata['is_primary_resource'] = false;

        return $metadata;
    }

    /** @return array<string, int|float|string|bool>|null */
    public function forCurrentJobArt(?int $currentJobId, Skill $skill, string $origin): ?array
    {
        $artMetadata = $this->forArt($skill);
        if ($artMetadata === null
            || ! $this->prototypeCatalog->isTrustedArtProfile($skill)
        ) {
            return null;
        }

        if (! in_array($origin, ['current', 'inherited'], true)) {
            return null;
        }

        return array_merge($artMetadata, [
            'resource_origin' => 'equipped_lineage',
            'is_primary_resource' => false,
        ]);
    }

    public function usesBattleResource(BattleActor $actor, Skill $skill): bool
    {
        return $this->forActorArt($actor, $skill) !== null;
    }

    public function activatesResource(BattleActor $actor, Skill $skill): bool
    {
        $art = $this->forActorArt($actor, $skill);

        return $art !== null && $this->metadataActivatesResource($art);
    }

    public function usesPrimaryResource(BattleActor $actor, Skill $skill): bool
    {
        // 「主資源」というプレイヤー概念は廃止した。互換APIは残すが、
        // 各カードは常に自分の系譜資源を使うため primary 扱いにはしない。
        return false;
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
            && $this->prototypeCatalog->isSamePrimaryLineage($actor->currentJobId, $skill);
    }

    public function isCrossLineageInherited(BattleActor $actor, Skill $skill): bool
    {
        return $this->isInheritedOrigin($actor, $skill)
            && $this->prototypeCatalog->isTrustedArtProfile($skill)
            && ! $this->prototypeCatalog->isSamePrimaryLineage($actor->currentJobId, $skill);
    }

    /**
     * @return list<array{lineage_key:string,resource_key:string,resource_name:string,resource_max_points:int,is_primary_resource:bool}>
     */
    public function resourcesForActor(BattleActor $actor): array
    {
        $resources = [];
        foreach ($actor->jobArts as $skill) {
            if (! $skill instanceof Skill) {
                continue;
            }

            $art = $this->forActorArt($actor, $skill);
            if ($art === null || ! $this->metadataActivatesResource($art)) {
                continue;
            }

            $key = (string) $art['resource_key'];
            $resources[$key] ??= array_merge($art, [
                'lineage_key' => (string) $art['lineage_key'],
                'resource_key' => $key,
                'resource_name' => (string) $art['resource_name'],
                'resource_max_points' => (int) $art['resource_max_points'],
                'is_primary_resource' => false,
            ]);
        }

        return array_values($resources);
    }

    /**
     * 戦技セットの表示用。runtimeと同じmetadata判定で、
     * そのセットが実際に有効化する系譜資源だけを返す。
     *
     * @param iterable<mixed> $skills
     * @return list<array{lineage_key:string,resource_key:string,resource_name:string,resource_max_points:int,is_primary_resource:bool}>
     */
    public function resourcesForSkills(?int $currentJobId, iterable $skills): array
    {
        $resources = [];
        foreach ($skills as $skill) {
            if (! $skill instanceof Skill) {
                continue;
            }

            $origin = (string) $skill->getAttribute('job_art_origin');
            if (! in_array($origin, ['current', 'inherited'], true)) {
                $origin = (int) $skill->job_id === (int) $currentJobId ? 'current' : 'inherited';
            }
            $art = $this->forCurrentJobArt($currentJobId, $skill, $origin);
            if ($art === null || ! $this->metadataActivatesResource($art)) {
                continue;
            }

            $key = (string) $art['resource_key'];
            $resources[$key] ??= array_merge($art, [
                'lineage_key' => (string) $art['lineage_key'],
                'resource_key' => $key,
                'resource_name' => (string) $art['resource_name'],
                'resource_max_points' => (int) $art['resource_max_points'],
                'is_primary_resource' => false,
            ]);
        }

        return array_values($resources);
    }

    /** @param array<string, int|float|string|bool> $art */
    private function metadataActivatesResource(array $art): bool
    {
        if ((bool) ($art['activates_lineage_resource'] ?? false)) {
            return true;
        }

        return max(0, (int) ($art['resource_gain_points'] ?? 0)) > 0
            || max(0, (int) ($art['resource_gain_on_field_overwrite_points'] ?? 0)) > 0
            || max(0, (int) ($art['resource_cost_points'] ?? 0)) > 0
            || max(0, (int) ($art['minimum_resource_points'] ?? 0)) > 0;
    }

    private function originFor(BattleActor $actor, Skill $skill): string
    {
        return (string) ($actor->jobArtOrigins[(int) $skill->id]
            ?? ((int) $skill->job_id === (int) $actor->currentJobId ? 'current' : 'inherited'));
    }

    private function roles(): JobArtV2DeckRoleResolver
    {
        return $this->deckRoleResolver ?? app(JobArtV2DeckRoleResolver::class);
    }
}
