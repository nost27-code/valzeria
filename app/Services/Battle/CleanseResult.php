<?php

namespace App\Services\Battle;

final readonly class CleanseResult
{
    /**
     * @param list<string> $candidateStates
     * @param list<string> $removedStates
     */
    public function __construct(
        public int $sourceActionId,
        public string $actorKey,
        public array $candidateStates,
        public array $removedStates,
        public int $removedCount,
        public bool $success,
    ) {}

    /** @return array<string, array<int, string>|bool|int|string> */
    public function toArray(): array
    {
        return [
            'source_action_id' => $this->sourceActionId,
            'actor_key' => $this->actorKey,
            'candidate_states' => $this->candidateStates,
            'removed_states' => $this->removedStates,
            'removed_count' => $this->removedCount,
            'success' => $this->success,
        ];
    }
}
