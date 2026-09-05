<?php

namespace App\Services\Nation\Raid;

use Generator;
use LogicException;

/**
 * 同じPhase 1 generatorを、固定snapshotまたはライブplayerの1ターン単位で進める境界。
 */
final class NationRaidBossTurnSession
{
    private ?NationRaidBossTurnPrompt $prompt = null;

    private ?NationRaidBattleResult $result = null;

    public function __construct(private readonly Generator $turns)
    {
        $this->turns->rewind();
        $this->captureCurrentPromptOrResult();
    }

    public function finished(): bool
    {
        return $this->result !== null;
    }

    public function beginTurn(): NationRaidBossTurnPrompt
    {
        if ($this->prompt === null) {
            throw new LogicException('Raid boss turn session has no pending turn.');
        }

        return $this->prompt;
    }

    public function resolveTurn(
        NationRaidPlayerActionSnapshot $playerAction,
        ?NationRaidPlayerTurnState $livePlayerState = null,
    ): NationRaidBossTurnResolution {
        $prompt = $this->beginTurn();
        if ($playerAction->turn !== $prompt->turn) {
            throw new LogicException('Raid player action does not match the pending turn.');
        }

        $yielded = $this->turns->send(new NationRaidBossTurnCommand($playerAction, $livePlayerState));
        if (! $yielded instanceof NationRaidBossTurnResolution) {
            throw new LogicException('Raid boss turn generator did not return a turn resolution.');
        }

        $this->prompt = null;
        $this->turns->next();
        $this->captureCurrentPromptOrResult();

        return $yielded;
    }

    public function result(): NationRaidBattleResult
    {
        if ($this->result === null) {
            throw new LogicException('Raid boss turn session is not finished.');
        }

        return $this->result;
    }

    private function captureCurrentPromptOrResult(): void
    {
        if (! $this->turns->valid()) {
            $returned = $this->turns->getReturn();
            if (! $returned instanceof NationRaidBattleResult) {
                throw new LogicException('Raid boss turn generator did not return a battle result.');
            }
            $this->result = $returned;
            $this->prompt = null;

            return;
        }

        $current = $this->turns->current();
        if (! $current instanceof NationRaidBossTurnPrompt) {
            throw new LogicException('Raid boss turn generator is not waiting at a prompt boundary.');
        }
        $this->prompt = $current;
    }
}
