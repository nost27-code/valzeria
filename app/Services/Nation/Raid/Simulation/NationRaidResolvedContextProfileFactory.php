<?php

namespace App\Services\Nation\Raid\Simulation;

use App\Models\Character;
use App\Services\Nation\Raid\NationRaidBattleInput;
use App\Services\Nation\Raid\NationRaidPlayerSnapshot;
use App\Services\Nation\Raid\NationRaidRules;

/** 指定contextだけをライブbridgeで解決し、hash付きcompact cacheへ変換する。 */
final readonly class NationRaidResolvedContextProfileFactory
{
    public function __construct(
        private NationRaidTurnByTurnActionProfileBridge $bridge,
        private NationRaidResolvedProfileProjector $projector,
        private NationRaidResolvedProfileCacheHasher $hasher,
    ) {}

    /** @return list<array<string, mixed>> */
    public function profilesForContext(
        Character $character,
        NationRaidPlayerSnapshot $player,
        string $characterKey,
        int $stage,
        string $startingForm,
        string $strategy,
        ?string $dominantLineage,
        int $profileCount,
    ): array {
        $profiles = [];
        foreach (range(1, max(1, min(25, $profileCount))) as $profileNo) {
            $context = NationRaidResolvedProfileContext::forProfile(
                characterKey: $characterKey,
                stage: $stage,
                startingForm: $startingForm,
                strategy: $strategy,
                dominantLineage: $dominantLineage,
                profileNo: $profileNo,
            );
            $bridge = $this->bridge->resolveProfile($character, new NationRaidBattleInput(
                stage: $context->stage,
                cycleCurrentHp: $context->canonicalCycleCurrentHp(),
                cycleMaxHp: (new NationRaidRules)->stageMaxHp($context->stage),
                sourceCycleId: 'resolved-cache:'.$context->key(),
                dominantLineage: $context->dominantLineage,
                seed: $context->sortieSeed,
                strategy: $context->strategy,
                player: $player,
            ));
            $profiles[] = $this->projector->project($context, $bridge);
        }

        return $this->hasher->sealProfiles($profiles);
    }

    public function modelVersion(): string
    {
        return NationRaidResolvedProfileProjector::MODEL_VERSION;
    }

    public function contextContractHash(): string
    {
        return NationRaidResolvedProfileContext::contractHash();
    }
}
