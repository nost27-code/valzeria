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
        private readonly JobArtV2CrownBalanceCatalog $crownBalanceCatalog,
        private readonly ?JobArtV2ProgressionService $progressionService = null,
        private readonly ?JobArtV2DeckRoleResolver $deckRoleResolver = null,
    ) {
    }

    public function forExecution(BattleActor $actor, Skill $skill, ?BattleState $state = null): int
    {
        $deckRoles = ($this->deckRoleResolver ?? app(JobArtV2DeckRoleResolver::class))
            ->resolveActor($actor);
        $crownPierceTrusted = $deckRoles->active
            ? $this->allowsCDesignLineageLayer($deckRoles, $skill, JobArtV2DeckRole::MAIN)
            : $actor->currentJobId === 62
                && (string) ($actor->jobArtOrigins[(int) $skill->id] ?? 'current') === 'current'
                && $this->prototypeCatalog->isTrustedCurrentJobArt(62, $skill);
        $power = $this->resolve(
            $actor->currentJobId,
            $skill,
            (string) ($actor->jobArtOrigins[(int) $skill->id] ?? ''),
            $this->featureGate->usesResources($actor),
            $this->featureGate->usesPenetration($actor),
            $deckRoles,
        );
        if ((int) $skill->job_id === 62
            && (int) $skill->learn_rank === 9
            && $crownPierceTrusted
            && ($state === null
                || ! ($this->progressionService ?? app(JobArtV2ProgressionService::class))->crownPierceRankFiveUsed($actor))
        ) {
            $power = $this->canonicalBasePower($skill);
        }

        return $power;
    }

    /**
     * 星冠アストラルレイの上書き回数補正は、L列正本どおり
     * powerではなく最終ダメージへ掛ける。
     */
    public function applyFinalDamageModifiers(
        BattleActor $actor,
        Skill $sourceSkill,
        Skill $executionSkill,
        ?BattleState $state,
    ): void {
        $branch = $this->fieldOverwriteBranchForExecution($actor, $sourceSkill, $state);
        if ($branch === null || $branch['multiplier'] === 1.0) {
            return;
        }

        $current = max(0.0, (float) ($executionSkill->getAttribute('job_art_v2_target_damage_multiplier') ?? 1.0));
        $executionSkill->setAttribute(
            'job_art_v2_target_damage_multiplier',
            $current * (float) $branch['multiplier'],
        );
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
        $deckRoles = ($this->deckRoleResolver ?? app(JobArtV2DeckRoleResolver::class))
            ->resolveActor($actor);
        $trusted = $deckRoles->active
            ? $this->allowsCDesignLineageLayer($deckRoles, $skill, JobArtV2DeckRole::MAIN)
            : $actor->currentJobId === 63
                && $origin === 'current'
                && $this->prototypeCatalog->isTrustedCurrentJobArt(63, $skill);
        if ($state === null
            || (int) $skill->job_id !== 63
            || (int) $skill->learn_rank !== 9
            || ! $trusted
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

    public function forDisplay(?int $currentJobId, Skill $skill, ?iterable $loadoutSkills = null): int
    {
        if (! $this->featureGate->usesLoadoutUiForCurrentJob($currentJobId)) {
            return $this->canonicalBasePower($skill);
        }

        $deckRoles = $loadoutSkills !== null
            && $this->featureGate->usesCDesignPrototypeForCurrentJob($currentJobId)
                ? ($this->deckRoleResolver ?? app(JobArtV2DeckRoleResolver::class))
                    ->resolveSkills($currentJobId, $loadoutSkills)
                : null;
        $formalCDesignLineage = $deckRoles?->active === true
            && in_array(
                $deckRoles->roleFor($skill),
                [JobArtV2DeckRole::MAIN, JobArtV2DeckRole::SECONDARY],
                true,
            )
            && $deckRoles->blockReasonFor($skill) === null;

        $power = $this->resolve(
            $currentJobId,
            $skill,
            (string) $skill->getAttribute('job_art_origin'),
            $this->featureGate->usesResourcesForCurrentJob($currentJobId),
            $formalCDesignLineage
                ? $this->featureGate->usesPenetrationForCurrentJob((int) $skill->job_id)
                : $this->featureGate->usesPenetrationForCurrentJob($currentJobId),
            $deckRoles,
        );

        // 竜冠天穿槍の470%は戦闘中に連携を1回以上使用した場合だけ。
        // 編成画面ではL列正本の基礎威力355%を表示する。
        if ((int) $skill->job_id === 62 && (int) $skill->learn_rank === 9) {
            return $this->canonicalBasePower($skill);
        }

        return $power;
    }

    private function resolve(
        ?int $currentJobId,
        Skill $skill,
        string $origin,
        bool $resourcesEnabled,
        bool $penetrationEnabled,
        ?JobArtV2DeckRoleResolution $deckRoles,
    ): int
    {
        $masterPower = $this->canonicalBasePower($skill);
        $override = $this->powerCatalog->overrideFor($skill);

        if ($override === null || ! $resourcesEnabled) {
            return $masterPower;
        }

        $currentJobArt = $deckRoles?->active
            ? $this->allowsCDesignLineageLayer($deckRoles, $skill)
            : $origin === 'current'
                && $this->prototypeCatalog->isTrustedCurrentJobArt($currentJobId, $skill);
        $sameLineageInherited = ! ($deckRoles?->active ?? false)
            && $origin === 'inherited'
            && ($override['scope'] ?? 'current') === 'current_or_same_lineage'
            && $this->prototypeCatalog->isTrustedArtProfile($skill)
            && $this->prototypeCatalog->isSamePrimaryLineage($currentJobId, $skill);
        if (! $currentJobArt && ! $sameLineageInherited) {
            return $masterPower;
        }

        if ($override['requires'] === 'penetration' && ! $penetrationEnabled) {
            return $masterPower;
        }

        return $override['power'];
    }

    private function allowsCDesignLineageLayer(
        JobArtV2DeckRoleResolution $resolution,
        Skill $skill,
        ?JobArtV2DeckRole $requiredRole = null,
    ): bool {
        if (! $resolution->active || $resolution->blockReasonFor($skill) !== null) {
            return false;
        }

        $role = $resolution->roleFor($skill);

        return ($requiredRole === null
                ? in_array($role, [JobArtV2DeckRole::MAIN, JobArtV2DeckRole::SECONDARY], true)
                : $role === $requiredRole)
            && $this->prototypeCatalog->isTrustedArtProfile($skill);
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

    private function canonicalBasePower(Skill $skill): int
    {
        $metadata = $this->crownBalanceCatalog->forArt($skill);

        return max(0, (int) ($metadata['power'] ?? $skill->power));
    }
}
