<?php

namespace App\Services\Battle;

class DamageApplicationResult
{
    public function __construct(
        public readonly int $requestedDamage,
        public readonly int $hpBefore,
        public readonly int $hpAfter,
        public readonly int $actualHpLoss,
        public readonly int $overkillDamage,
        public readonly bool $wasLethal,
        public readonly DamageSourceType $sourceType,
        public readonly int|string|null $sourceId,
        public readonly ?HitResult $hitResult,
        public readonly int $hitIndex,
        public readonly int $hitCount,
    ) {
    }
}
