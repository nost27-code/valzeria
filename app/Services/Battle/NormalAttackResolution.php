<?php

namespace App\Services\Battle;

final class NormalAttackResolution
{
    public function __construct(
        public readonly int $sourceActionId,
        public readonly string $attackerKey,
        public readonly string $targetKey,
        public readonly HitResult $hitResult,
        public readonly BattleActionType $resolvedActionType,
        public readonly string $damageCategory,
    ) {
    }
}
