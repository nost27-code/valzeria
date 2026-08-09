<?php

namespace App\Services\Battle;

use App\Models\Skill;
use App\Services\JobArtV2ActiveEvasionProvider;
use App\Services\JobArtV2FeatureGate;
use App\Services\JobArtV2HitRandomSource;
use App\Services\JobArtV2FieldService;
use App\Services\JobArtV2PrototypeCatalog;
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
    ) {
    }

    public function resolveJobArt(
        BattleActor $attacker,
        BattleActor $defender,
        Skill $skill,
        string $battleType,
        ?BattleState $state = null,
    ): ?HitResult {
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

        if (!$this->isSureHit($skill)) {
            $hitRate = $this->baseHitChance($attacker, $defender, $skill, $battleType, $state);
            if ($this->random->percentRoll() > $hitRate) {
                return HitResult::MISS;
            }
        }

        $evasionRate = max(0.0, min(100.0, $this->activeEvasion->rate($attacker, $defender, $skill, $battleType)));
        if ($evasionRate > 0.0 && $this->random->percentRoll() <= $evasionRate) {
            return HitResult::EVADE;
        }

        return HitResult::HIT;
    }

    private function baseHitChance(
        BattleActor $attacker,
        BattleActor $defender,
        Skill $skill,
        string $battleType,
        ?BattleState $state,
    ): float {
        $explicitAccuracy = $this->explicitAccuracy($skill);
        if ($explicitAccuracy === null && in_array($battleType, ['pvp', 'champ', 'arena_npc'], true)) {
            // These legacy job-art paths do not perform a base hit roll.
            return 100.0;
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
        $delta += $this->actionLocalAccuracyDelta($attacker, $skill);

        return max($rules['min_rate'], min($rules['max_rate'], $hitRate + $delta));
    }

    private function actionLocalAccuracyDelta(BattleActor $attacker, Skill $skill): float
    {
        if (! $this->featureGate->usesResources($attacker)
            || $attacker->currentJobId !== 65
            || ($attacker->jobArtOrigins[(int) $skill->id] ?? 'current') !== 'current'
        ) {
            return 0.0;
        }

        $catalog = $this->prototypeCatalog ?? app(JobArtV2PrototypeCatalog::class);
        if (! $catalog->isTrustedCurrentJobArt(65, $skill)) {
            return 0.0;
        }

        $metadata = $catalog->artResourceMetadata($skill);

        return max(0.0, (float) ($metadata['accuracy_delta_points'] ?? 0.0));
    }

    /** @return array{agi_factor: float, min_rate: int, max_rate: int} */
    private function hitRules(string $battleType): array
    {
        return match ($battleType) {
            'pvp', 'arena_npc' => ['agi_factor' => 0.08, 'min_rate' => 84, 'max_rate' => 97],
            'champ' => ['agi_factor' => 0.15, 'min_rate' => 75, 'max_rate' => 98],
            default => ['agi_factor' => 0.5, 'min_rate' => 70, 'max_rate' => 98],
        };
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
}
