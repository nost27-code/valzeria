<?php

namespace App\Services;

use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\CleanseResult;

/**
 * 奥義v2で明示された有害状態だけを解除する、戦闘中専用の純粋な入口。
 */
final class JobArtV2CleanseService
{
    /** @var list<string> */
    public const HARMFUL_STATES = [
        'burn',
        'poison',
        'bleed',
        'def_down',
        'slow',
        'recovery_block',
    ];

    public function canCleanse(BattleActor $actor): bool
    {
        foreach (self::HARMFUL_STATES as $conditionKey) {
            if (array_key_exists($conditionKey, $actor->conditions)) {
                return true;
            }
        }

        return false;
    }

    public function cleanse(
        BattleActor $actor,
        BattleState $state,
        int $sourceActionId,
        bool $removeAll = true,
    ): CleanseResult {
        $removed = [];
        foreach (self::HARMFUL_STATES as $conditionKey) {
            if (! array_key_exists($conditionKey, $actor->conditions)) {
                continue;
            }

            unset($actor->conditions[$conditionKey]);
            $removed[] = $conditionKey;
            if (! $removeAll) {
                break;
            }
        }

        $result = new CleanseResult(
            sourceActionId: $sourceActionId,
            actorKey: $state->actorKey($actor),
            candidateStates: self::HARMFUL_STATES,
            removedStates: $removed,
            removedCount: count($removed),
            success: $removed !== [],
        );
        $state->recordCleanseResult($result);

        return $result;
    }
}
