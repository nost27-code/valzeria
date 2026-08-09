<?php

namespace App\Services;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;

class JobArtV2FinisherConditionProvider
{
    public function __construct(
        private readonly ?JobArtV2ResourceService $resourceService = null,
    ) {
    }

    public function isSatisfied(BattleActor $actor, BattleState $state, Skill $skill): bool
    {
        return ($this->resourceService ?? app(JobArtV2ResourceService::class))
            ->isFinisherReady($actor, $skill);
    }
}
