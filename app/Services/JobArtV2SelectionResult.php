<?php

namespace App\Services;

use App\Models\Skill;

class JobArtV2SelectionResult
{
    public function __construct(
        public readonly ?Skill $skill,
        public readonly ?int $candidateSkillId,
        public readonly ?int $activationRate,
        public readonly bool $activated,
        public readonly bool $retriedAfterMiss,
        public readonly bool $rankNinePrioritized,
        /** @var array<int, string> */
        public readonly array $blockedReasons = [],
    ) {
    }
}
