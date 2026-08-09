<?php

namespace App\Services;

final readonly class JobArtV2BreakDebuffResult
{
    public function __construct(
        public bool $applied,
        public string $event,
        public string $targetActorKey,
        public float $previousRate,
        public float $rate,
        public int $previousRemainingRounds,
        public int $remainingRounds,
        public int|string $sourceActionId,
    ) {}

    /** @return array<string, bool|float|int|string> */
    public function toArray(): array
    {
        return [
            'applied' => $this->applied,
            'event' => $this->event,
            'target_actor_key' => $this->targetActorKey,
            'previous_rate' => $this->previousRate,
            'rate' => $this->rate,
            'previous_remaining_rounds' => $this->previousRemainingRounds,
            'remaining_rounds' => $this->remainingRounds,
            'source_action_id' => $this->sourceActionId,
        ];
    }
}
