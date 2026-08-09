<?php

namespace App\Services\Battle;

class DamageApplicationRequest
{
    public function __construct(
        public readonly ?BattleActor $sourceActor,
        public readonly BattleActor $targetActor,
        public readonly int $resolvedDamage,
        public readonly DamageSourceType $sourceType,
        public readonly int|string|null $sourceId,
        public readonly string $battleType,
        public readonly ?HitResult $hitResult = null,
        public readonly int $hitIndex = 1,
        public readonly int $hitCount = 1,
        public readonly ?BattleState $battleState = null,
        public readonly ?DirectAttackResolution $directAttackResolution = null,
    ) {
    }
}
