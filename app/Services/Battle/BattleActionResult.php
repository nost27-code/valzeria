<?php

namespace App\Services\Battle;

final class BattleActionResult
{
    public function __construct(
        public readonly int $sourceActionId,
        public readonly string $actorKey,
        public readonly string $targetKey,
        public readonly BattleActionType $actionType,
    ) {
    }
}
