<?php

namespace App\Services;

/** Battle-memory-only Rank5 use state, partitioned by lineage resource. */
final class JobArtV2Rank5CycleState
{
    /** @var array<string, array<int, true>> */
    private array $usedSkillIds = [];

    public function hasUsed(string $resourceKey, int $skillId): bool
    {
        return isset($this->usedSkillIds[$resourceKey][$skillId]);
    }

    public function markUsed(string $resourceKey, int $skillId): void
    {
        $this->usedSkillIds[$resourceKey][$skillId] = true;
    }

    public function clearResource(string $resourceKey): void
    {
        unset($this->usedSkillIds[$resourceKey]);
    }

    /** @return list<int> */
    public function usedSkillIds(string $resourceKey): array
    {
        return array_map('intval', array_keys($this->usedSkillIds[$resourceKey] ?? []));
    }
}
