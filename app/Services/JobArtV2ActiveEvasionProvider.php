<?php

namespace App\Services;

use App\Models\Skill;
use App\Services\Battle\BattleActor;

class JobArtV2ActiveEvasionProvider
{
    public function __construct(
        private readonly ?JobArtV2ProgressionService $progressionService = null,
    ) {}

    public function rate(BattleActor $attacker, BattleActor $defender, Skill $skill, string $battleType): float
    {
        return ($this->progressionService ?? app(JobArtV2ProgressionService::class))
            ->activeEvasionRate($attacker, $defender) * 100;
    }
}
