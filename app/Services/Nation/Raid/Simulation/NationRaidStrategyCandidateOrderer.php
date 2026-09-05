<?php

namespace App\Services\Nation\Raid\Simulation;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\JobArtV2EffectClassifier;
use App\Services\JobArtV2ResourceCatalog;
use App\Services\Nation\Raid\NationRaidRules;
use App\Services\ResourceRole;

/** レイド作戦を、既存auto selection直前の安定した候補順へだけ反映する。 */
final readonly class NationRaidStrategyCandidateOrderer
{
    public function __construct(
        private JobArtV2EffectClassifier $effectClassifier,
        private JobArtV2ResourceCatalog $resourceCatalog,
    ) {}

    /**
     * @param  list<Skill>  $candidates  既存ボス戦戦略で並べた装備中候補
     * @param  callable(Skill): bool  $isEligible
     * @param  callable(Skill): bool  $isReadyUltimate
     * @param  callable(Skill): bool  $isResponseCandidate
     * @return list<Skill>
     */
    public function order(
        string $strategy,
        BattleActor $actor,
        array $candidates,
        callable $isEligible,
        callable $isReadyUltimate,
        callable $isResponseCandidate,
    ): array {
        if ($candidates === []) {
            return [];
        }

        $preferred = match ($strategy) {
            NationRaidRules::STRATEGY_ASSAULT => fn (Skill $skill): bool => $isEligible($skill)
                && ($this->effectClassifier->isDamageArt($skill, $actor->currentJobId)
                    || in_array(
                        $this->resourceCatalog->roleForActorArt($actor, $skill),
                        [ResourceRole::CONSUMER, ResourceRole::FINISHER],
                        true,
                    )
                    || $isReadyUltimate($skill)),
            NationRaidRules::STRATEGY_INTERCEPT => fn (Skill $skill): bool => $isEligible($skill)
                && $isResponseCandidate($skill),
            NationRaidRules::STRATEGY_FORTIFY => fn (Skill $skill): bool => $isEligible($skill)
                && ($this->effectClassifier->isHealingArt($skill, $actor->currentJobId)
                    || $this->effectClassifier->isGuardArt($skill, $actor->currentJobId)
                    || $this->effectClassifier->isCleanseArt($skill, $actor->currentJobId)),
            NationRaidRules::STRATEGY_BOSS_SET => null,
            default => null,
        };

        if ($preferred === null) {
            return $candidates;
        }

        $priority = [];
        $fallback = [];
        foreach ($candidates as $skill) {
            if ($preferred($skill)) {
                $priority[] = $skill;
            } else {
                $fallback[] = $skill;
            }
        }

        // 成立候補がない時と同分類内では、既存ボス戦戦略の順をそのまま保つ。
        return $priority === [] ? $candidates : [...$priority, ...$fallback];
    }
}
