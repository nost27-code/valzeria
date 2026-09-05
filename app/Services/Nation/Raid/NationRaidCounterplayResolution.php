<?php

namespace App\Services\Nation\Raid;

final readonly class NationRaidCounterplayResolution
{
    public function __construct(
        public string $identity,
        public string $effect,
        public bool $applied,
        public float $playerDamageMultiplier = 1.0,
        public float $bossDefenseIgnoreRate = 0.0,
        public ?float $telegraphReductionOverride = null,
        public float $additionalTelegraphReduction = 0.0,
        public bool $suppressUniqueEffect = false,
        public bool $delay = false,
        public int $bossSpLoss = 0,
        public int $bossRecoverySlowCharges = 0,
        public int $postResolutionDamage = 0,
        public bool $blockAttachedInterference = false,
        public bool $gainSwordFocusOnMitigation = false,
        public bool $preparationDestroyed = false,
        public int $breakMarksConsumed = 0,
        public ?string $notAppliedReason = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
