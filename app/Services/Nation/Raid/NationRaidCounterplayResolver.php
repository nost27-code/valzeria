<?php

namespace App\Services\Nation\Raid;

use InvalidArgumentException;

/** レイド予告だけに適用する11戦技adapter。既存戦技stateやresourceへ書き込まない。 */
final class NationRaidCounterplayResolver
{
    public function __construct(private readonly NationRaidRules $rules) {}

    public function isSelectable(string $identity, NationRaidCounterplayContext $context): bool
    {
        $art = $this->rules->counterplayArt($identity);
        if ($art === null) {
            return false;
        }

        return match ($art['effect']) {
            'counter_intercept', 'ultimate_guard', 'fortress_guard' => $context->canBeGuarded,
            'hunt_cancel' => $context->huntingMarkCount > 0,
            'break_preparation' => $context->breakMarkCount > 0 && $context->preparation?->isActive() === true,
            'readiness_delay' => ! $context->alreadyDelayed,
            default => true,
        };
    }

    public function resolve(string $identity, NationRaidCounterplayContext $context): NationRaidCounterplayResolution
    {
        $art = $this->rules->counterplayArt($identity);
        if ($art === null) {
            throw new InvalidArgumentException("Unknown raid counterplay identity: {$identity}");
        }

        if (! $this->isSelectable($identity, $context)) {
            return new NationRaidCounterplayResolution(
                identity: $identity,
                effect: $art['effect'],
                applied: false,
                notAppliedReason: 'selection_conditions_not_met',
            );
        }

        return match ($art['effect']) {
            'counter_intercept' => new NationRaidCounterplayResolution(
                identity: $identity,
                effect: $art['effect'],
                applied: true,
                additionalTelegraphReduction: 0.20,
                gainSwordFocusOnMitigation: true,
            ),
            'eclipse_backlash' => new NationRaidCounterplayResolution(
                identity: $identity,
                effect: $art['effect'],
                applied: $context->hit,
                postResolutionDamage: $context->hit ? 5_000 : 0,
                notAppliedReason: $context->hit ? null : 'miss_or_evade',
            ),
            'pierce_opening' => new NationRaidCounterplayResolution(
                identity: $identity,
                effect: $art['effect'],
                applied: true,
                playerDamageMultiplier: 1.15,
                bossDefenseIgnoreRate: 0.50,
            ),
            'field_suppression' => new NationRaidCounterplayResolution(
                identity: $identity,
                effect: $art['effect'],
                applied: true,
                suppressUniqueEffect: true,
            ),
            'hunt_cancel' => new NationRaidCounterplayResolution(
                identity: $identity,
                effect: $art['effect'],
                applied: $context->hit,
                delay: $context->hit,
                notAppliedReason: $context->hit ? null : 'miss_or_evade',
            ),
            'aim_sp_pressure' => new NationRaidCounterplayResolution(
                identity: $identity,
                effect: $art['effect'],
                applied: $context->hit,
                bossSpLoss: $context->hit
                    ? min($context->bossSp, max(1, (int) floor($context->bossMaxSp * 0.03)))
                    : 0,
                notAppliedReason: $context->hit ? null : 'miss_or_evade',
            ),
            'ultimate_guard' => new NationRaidCounterplayResolution(
                identity: $identity,
                effect: $art['effect'],
                applied: true,
                telegraphReductionOverride: 0.35,
            ),
            'fortress_guard' => new NationRaidCounterplayResolution(
                identity: $identity,
                effect: $art['effect'],
                applied: true,
                telegraphReductionOverride: 0.50,
                blockAttachedInterference: true,
            ),
            'transmute_resource_slow' => new NationRaidCounterplayResolution(
                identity: $identity,
                effect: $art['effect'],
                applied: $context->hit,
                bossRecoverySlowCharges: $context->hit ? 2 : 0,
                notAppliedReason: $context->hit ? null : 'miss_or_evade',
            ),
            'break_preparation' => $this->resolvePreparationBreak($identity, $art['effect'], $context),
            'readiness_delay' => new NationRaidCounterplayResolution(
                identity: $identity,
                effect: $art['effect'],
                applied: true,
                delay: true,
            ),
            default => throw new InvalidArgumentException("Unsupported raid counterplay effect: {$art['effect']}"),
        };
    }

    private function resolvePreparationBreak(
        string $identity,
        string $effect,
        NationRaidCounterplayContext $context,
    ): NationRaidCounterplayResolution {
        if (! $context->hit) {
            return new NationRaidCounterplayResolution(
                identity: $identity,
                effect: $effect,
                applied: false,
                notAppliedReason: 'miss_or_evade',
            );
        }

        $destroyed = $context->preparation?->destroy() ?? false;

        return new NationRaidCounterplayResolution(
            identity: $identity,
            effect: $effect,
            applied: $destroyed,
            preparationDestroyed: $destroyed,
            breakMarksConsumed: $destroyed ? 1 : 0,
            notAppliedReason: $destroyed ? null : 'no_destroyable_raid_preparation',
        );
    }
}
