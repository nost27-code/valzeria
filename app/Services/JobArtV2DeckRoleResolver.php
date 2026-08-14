<?php

namespace App\Services;

use App\Models\Skill;
use App\Services\Battle\BattleActor;

/**
 * 戦技v2の装備可否を解決する互換層。
 *
 * プレイヤー向けの主／副／出張区分は廃止した。習得済みの信頼済み戦技は
 * すべてカード本文どおりの全効果を使う。内部roleは既存サービスとの互換の
 * ためMAINへ統一するが、現在職の系譜を表す意味は持たない。
 */
final class JobArtV2DeckRoleResolver
{
    public const SECONDARY_PRODUCER_GAIN = 4;

    public const BLOCKED_INVALID_COMPOSITION = 'blocked_by_c_design_invalid_composition';
    public const BLOCKED_UNSUPPORTED_ART = 'blocked_by_c_design_unsupported_art';
    public const BLOCKED_SECONDARY_FINISHER = 'blocked_by_c_design_secondary_finisher';
    public const BLOCKED_TECH_RANK = 'blocked_by_c_design_tech_rank';
    public const BLOCKED_NONPORTABLE_TECH = 'blocked_by_c_design_nonportable_tech';

    public function __construct(
        private readonly JobArtLineageCatalog $lineageCatalog,
        private readonly JobArtV2PrototypeCatalog $prototypeCatalog,
        private readonly JobArtV2CDesignCatalog $cDesignCatalog,
    ) {}

    public function supportsCurrentJob(?int $jobId): bool
    {
        $lineage = $this->lineageCatalog->forJob($jobId)['lineage_key'] ?? null;

        return $this->enabledByConfig()
            && $this->prototypeCatalog->supportsCurrentJob($jobId)
            && $this->cDesignCatalog->supportsLineage($lineage);
    }

    public function resolveActor(BattleActor $actor): JobArtV2DeckRoleResolution
    {
        return $this->resolveSkills($actor->currentJobId, $actor->jobArts);
    }

    /** @param iterable<mixed> $skills */
    public function resolveSkills(?int $currentJobId, iterable $skills): JobArtV2DeckRoleResolution
    {
        if (! $this->supportsCurrentJob($currentJobId)) {
            return JobArtV2DeckRoleResolution::inactive();
        }

        $arts = [];
        foreach ($skills as $skill) {
            if (! $skill instanceof Skill) {
                continue;
            }
            $arts[] = $skill;
        }
        if ($arts === []) {
            return JobArtV2DeckRoleResolution::inactive();
        }

        $mainLineage = $this->lineageCatalog->forJob($currentJobId)['lineage_key'] ?? null;
        $lineages = [];
        $unsupported = [];
        $formalLineages = [];
        foreach ($arts as $skill) {
            $key = JobArtV2DeckRoleResolution::artKey($skill);
            $lineage = $this->lineageCatalog->forArt($skill)['lineage_key'] ?? null;
            if (! $this->cDesignCatalog->supportsLineage($lineage)
                || ! $skill->isJobArt()
                || ! $this->prototypeCatalog->isTrustedArtProfile($skill)
                || $this->prototypeCatalog->artResourceMetadata($skill) === null
            ) {
                $unsupported[$key] = true;
                $lineages[$key] = null;
                continue;
            }
            $lineages[$key] = $lineage;
            if (is_string($lineage) && ! in_array($lineage, $formalLineages, true)) {
                $formalLineages[] = $lineage;
            }
        }

        $roles = [];
        $blockReasons = [];
        foreach ($arts as $skill) {
            $key = JobArtV2DeckRoleResolution::artKey($skill);
            $roles[$key] = JobArtV2DeckRole::MAIN;
            if (isset($unsupported[$key])) {
                $blockReasons[$key] = self::BLOCKED_UNSUPPORTED_ART;
            }
        }

        return new JobArtV2DeckRoleResolution(
            active: true,
            mainLineage: $mainLineage,
            secondaryLineage: null,
            rolesByArtKey: $roles,
            blockReasonsByArtKey: $blockReasons,
            formalLineages: $formalLineages,
        );
    }

    public function roleForActorArt(BattleActor $actor, Skill $skill): ?JobArtV2DeckRole
    {
        return $this->resolveActor($actor)->roleFor($skill);
    }

    public function eligibilityBlockReason(BattleActor $actor, Skill $skill): ?string
    {
        $resolution = $this->resolveActor($actor);

        return $resolution->active ? $resolution->blockReasonFor($skill) : null;
    }

    public function hasFormalLineage(BattleActor $actor, string $lineage): bool
    {
        return $this->resolveActor($actor)->hasFormalLineage($lineage);
    }

    public function secondaryProducerGain(): int
    {
        return self::SECONDARY_PRODUCER_GAIN;
    }

    private function enabledByConfig(): bool
    {
        return (bool) config('battle.job_art_v2.dynamic_single', false)
            && (bool) config('battle.job_art_v2.hit_resolution', false)
            && (bool) config('battle.job_art_v2.damage_application', false)
            && (bool) config('battle.job_art_v2.resources', false);
    }
}
