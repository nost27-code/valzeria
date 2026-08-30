<?php

namespace App\Services\Battle;

use App\Models\Skill;
use App\Services\JobArtV2ActiveEvasionProvider;
use App\Services\JobArtV2DeckRole;
use App\Services\JobArtV2DeckRoleResolver;
use App\Services\JobArtV2FeatureGate;
use App\Services\JobArtV2HitRandomSource;
use App\Services\JobArtV2FieldService;
use App\Services\JobArtLineageCatalog;
use App\Services\JobArtV2PrototypeCatalog;
use App\Services\JobArtV2ProgressionService;
use App\Services\JobArtV2Rank5V6Catalog;
use App\Services\JobArtV2RoleEffectCatalog;
use App\Services\JobArtV2RoleEffectService;
use App\Support\JobArtEffectCatalog;

class ActionResolver
{
    private const LEGACY_PVE_JOB_ART_ACCURACY = 100;

    public function __construct(
        private readonly JobArtV2FeatureGate $featureGate,
        private readonly DamageCalculator $damageCalculator,
        private readonly JobArtV2HitRandomSource $random,
        private readonly JobArtV2ActiveEvasionProvider $activeEvasion,
        private readonly ?JobArtV2FieldService $fieldService = null,
        private readonly ?JobArtV2PrototypeCatalog $prototypeCatalog = null,
        private readonly ?JobArtV2RoleEffectCatalog $roleEffectCatalog = null,
        private readonly ?JobArtV2ProgressionService $progressionService = null,
        private readonly ?JobArtV2DeckRoleResolver $deckRoleResolver = null,
        private readonly ?CompetitiveHitPolicy $competitiveHitPolicy = null,
        private readonly ?JobArtLineageCatalog $lineageCatalog = null,
        private readonly ?JobArtV2Rank5V6Catalog $rank5V6Catalog = null,
        private readonly ?JobArtV2RoleEffectService $roleEffectService = null,
    ) {
    }

    public function resolveJobArt(
        BattleActor $attacker,
        BattleActor $defender,
        Skill $skill,
        string $battleType,
        ?BattleState $state = null,
    ): ?HitResult {
        return $this->resolveJobArtWithDetails(
            $attacker,
            $defender,
            $skill,
            $battleType,
            $state,
        )?->hitResult;
    }

    public function resolveJobArtWithDetails(
        BattleActor $attacker,
        BattleActor $defender,
        Skill $skill,
        string $battleType,
        ?BattleState $state = null,
    ): ?JobArtHitResolution {
        if ($state !== null
            && ($this->fieldService ?? app(JobArtV2FieldService::class))->isFieldOnlyArt($attacker, $state, $skill)
        ) {
            return null;
        }
        $template = (string) $skill->effect_template;
        if (!$this->featureGate->usesHitResolution($attacker)
            || !$skill->isJobArt()
            || !JobArtEffectCatalog::has($template)
            || !JobArtEffectCatalog::dealsDamage($template)
        ) {
            return null;
        }

        $sureHit = $this->isSureHit($skill);
        $rawHitChance = null;
        $effectiveHitChance = null;
        if (! $sureHit) {
            $chance = $this->hitChance($attacker, $defender, $skill, $battleType, $state);
            $rawHitChance = $chance['raw'];
            $effectiveHitChance = $chance['effective'];
            if ($this->random->percentRoll() > $effectiveHitChance) {
                return new JobArtHitResolution(
                    HitResult::MISS,
                    $rawHitChance,
                    $effectiveHitChance,
                    0.0,
                    0.0,
                    false,
                    false,
                );
            }
        }

        $evasionRate = max(0.0, min(100.0, $this->activeEvasion->rate($attacker, $defender, $skill, $battleType)));
        if ($evasionRate > 0.0 && $this->random->percentRoll() <= $evasionRate) {
            $this->activeEvasion->consumeMarkOnSuccessfulEvasion($attacker, $defender);

            return new JobArtHitResolution(
                HitResult::EVADE,
                $rawHitChance,
                $effectiveHitChance,
                0.0,
                0.0,
                false,
                $sureHit,
            );
        }

        $accuracyOverflow = ! $sureHit
            && $rawHitChance !== null
            && $this->isCompetitiveAimArt($skill, $battleType)
                ? max(0.0, $rawHitChance - 100.0)
                : 0.0;
        $vitalHitChance = ! $sureHit && $this->isCompetitiveAimArt($skill, $battleType)
            ? ($this->competitiveHitPolicy ?? app(CompetitiveHitPolicy::class))->vitalHitChance(
                $battleType,
                $accuracyOverflow,
                $this->criticalBonusPoints($attacker, $skill),
            )
            : 0.0;
        $vitalHit = $vitalHitChance > 0.0 && $this->random->percentRoll() <= $vitalHitChance;

        return new JobArtHitResolution(
            HitResult::HIT,
            $rawHitChance,
            $effectiveHitChance,
            $accuracyOverflow,
            $vitalHitChance,
            $vitalHit,
            $sureHit,
        );
    }

    /** @return array{raw:float,effective:float} */
    private function hitChance(
        BattleActor $attacker,
        BattleActor $defender,
        Skill $skill,
        string $battleType,
        ?BattleState $state,
    ): array {
        $explicitAccuracy = $this->explicitAccuracy($skill);
        $roleAccuracyDelta = $this->actionLocalAccuracyDelta($attacker, $skill, $battleType, $state);
        if ($explicitAccuracy === null
            && ($roleAccuracyDelta <= 0.0 || $this->preservesLegacySureHit($attacker, $skill))
            && $battleType === 'arena_npc'
        ) {
            // NPC rank battles retain their legacy guaranteed base hit.
            return ['raw' => 100.0, 'effective' => 100.0];
        }

        $rules = $this->hitRules($battleType);

        $hitRate = $this->damageCalculator->calculateHitChance(
            $attacker,
            $defender,
            $explicitAccuracy ?? self::LEGACY_PVE_JOB_ART_ACCURACY,
            $rules['agi_factor'],
            $rules['min_rate'],
            $rules['max_rate'],
        );
        $delta = $state !== null
            ? ($this->fieldService ?? app(JobArtV2FieldService::class))->accuracyDelta($attacker, $state)
            : 0.0;
        $delta += $roleAccuracyDelta;

        $maximum = $this->isCompetitiveAimArt($skill, $battleType)
            ? (float) $rules['aim_max_rate']
            : (float) $rules['max_rate'];
        if ($this->featureGate->usesResources($attacker) && $this->allowsRoleMetadata($attacker, $skill)) {
            $roleCatalog = $this->roleEffectCatalog ?? app(JobArtV2RoleEffectCatalog::class);
            $roleMetadata = $roleCatalog->forArt($skill);
            if ($roleCatalog->isPortable($skill) && is_numeric($roleMetadata['accuracy_max_percent'] ?? null)) {
                $maximum = min($maximum, (float) $roleMetadata['accuracy_max_percent']);
            }
        }

        $raw = $hitRate + $delta;

        return [
            'raw' => $raw,
            'effective' => max((float) $rules['min_rate'], min($maximum, $raw)),
        ];
    }

    private function preservesLegacySureHit(BattleActor $attacker, Skill $skill): bool
    {
        if (! $this->featureGate->usesResources($attacker)) {
            return false;
        }

        $roleCatalog = $this->roleEffectCatalog ?? app(JobArtV2RoleEffectCatalog::class);
        $roleMetadata = $roleCatalog->forArt($skill);

        return $this->allowsRoleMetadata($attacker, $skill)
            && $roleCatalog->isPortable($skill)
            && (bool) ($roleMetadata['preserve_legacy_sure_hit'] ?? false);
    }

    private function actionLocalAccuracyDelta(
        BattleActor $attacker,
        Skill $skill,
        string $battleType,
        ?BattleState $state = null,
    ): float
    {
        if (! $this->featureGate->usesResources($attacker)) {
            return 0.0;
        }

        $directDelta = 0.0;
        $roleCatalog = $this->roleEffectCatalog ?? app(JobArtV2RoleEffectCatalog::class);
        $roleMetadata = $roleCatalog->forArt($skill);
        if ($this->allowsRoleMetadata($attacker, $skill)
            && $roleCatalog->isPortable($skill)
            && is_numeric($roleMetadata['accuracy_delta_points'] ?? null)
        ) {
            $directDelta = max($directDelta, (float) $roleMetadata['accuracy_delta_points']);
        }

        $progressionService = $this->progressionService ?? app(JobArtV2ProgressionService::class);
        $directDelta = max($directDelta, $progressionService->accuracyDeltaPoints($attacker, $skill));

        if ((int) $skill->job_id === 65) {
            $catalog = $this->prototypeCatalog ?? app(JobArtV2PrototypeCatalog::class);
            $resolution = ($this->deckRoleResolver ?? app(JobArtV2DeckRoleResolver::class))
                ->resolveActor($attacker);
            $trusted = $resolution->active
                ? in_array(
                    $resolution->roleFor($skill),
                    [JobArtV2DeckRole::MAIN, JobArtV2DeckRole::SECONDARY],
                    true,
                ) && $resolution->blockReasonFor($skill) === null
                    && (int) $skill->job_id === 65
                    && $catalog->isTrustedArtProfile($skill)
                : $catalog->isTrustedCurrentJobArt(65, $skill);
            if ($trusted) {
                $metadata = $catalog->artResourceMetadata($skill);
                $directDelta = max(
                    $directDelta,
                    max(0.0, (float) ($metadata['accuracy_delta_points'] ?? 0.0)),
                );
            }
        }

        $competitivePolicy = $this->competitiveHitPolicy ?? app(CompetitiveHitPolicy::class);
        if ($competitivePolicy->supports($battleType) && $this->featureGate->usesRank5V6($attacker)) {
            $directDelta = max(
                $directDelta,
                ($this->rank5V6Catalog ?? app(JobArtV2Rank5V6Catalog::class))->accuracyBonusPoints($skill),
            );
        }

        return max(0.0, $directDelta)
            + ($competitivePolicy->supports($battleType)
                ? $progressionService->preparedAccuracyDeltaPoints($attacker, $skill, $state)
                : 0.0);
    }

    /** @return array{agi_factor:float,min_rate:int,max_rate:int,aim_max_rate:int} */
    private function hitRules(string $battleType): array
    {
        $competitivePolicy = $this->competitiveHitPolicy ?? app(CompetitiveHitPolicy::class);
        if ($competitivePolicy->supports($battleType)) {
            $rules = $competitivePolicy->rulesFor($battleType);

            return [
                'agi_factor' => $rules['agi_factor'],
                'min_rate' => $rules['min_rate'],
                'max_rate' => $rules['normal_max_rate'],
                'aim_max_rate' => $rules['aim_max_rate'],
            ];
        }

        return match ($battleType) {
            'arena_npc' => ['agi_factor' => 0.08, 'min_rate' => 84, 'max_rate' => 97, 'aim_max_rate' => 97],
            default => ['agi_factor' => 0.5, 'min_rate' => 70, 'max_rate' => 98, 'aim_max_rate' => 98],
        };
    }

    private function isCompetitiveAimArt(Skill $skill, string $battleType): bool
    {
        $policy = $this->competitiveHitPolicy ?? app(CompetitiveHitPolicy::class);

        return $policy->supports($battleType)
            && (($this->lineageCatalog ?? app(JobArtLineageCatalog::class))->forArt($skill)['lineage_key'] ?? null) === 'aim';
    }

    private function criticalBonusPoints(BattleActor $actor, Skill $skill): float
    {
        $bonus = ($this->roleEffectService ?? app(JobArtV2RoleEffectService::class))
            ->criticalBonusPoints($actor, $skill);
        if ($this->featureGate->usesRank5V6($actor)) {
            $bonus = max(
                $bonus,
                ($this->rank5V6Catalog ?? app(JobArtV2Rank5V6Catalog::class))->criticalBonusPoints($skill),
            );
        }

        return max(0.0, $bonus);
    }

    private function isSureHit(Skill $skill): bool
    {
        $attributes = $skill->getAttributes();

        return array_key_exists('sure_hit', $attributes)
            && filter_var($attributes['sure_hit'], FILTER_VALIDATE_BOOL);
    }

    private function explicitAccuracy(Skill $skill): ?int
    {
        $attributes = $skill->getAttributes();
        if (!array_key_exists('accuracy', $attributes) || !is_numeric($attributes['accuracy'])) {
            return null;
        }

        return (int) $attributes['accuracy'];
    }

    private function allowsRoleMetadata(BattleActor $actor, Skill $skill): bool
    {
        $resolution = ($this->deckRoleResolver ?? app(JobArtV2DeckRoleResolver::class))
            ->resolveActor($actor);
        if (! $resolution->active) {
            return true;
        }

        return in_array(
            $resolution->roleFor($skill),
            [JobArtV2DeckRole::MAIN, JobArtV2DeckRole::SECONDARY],
            true,
        ) && $resolution->blockReasonFor($skill) === null;
    }
}
