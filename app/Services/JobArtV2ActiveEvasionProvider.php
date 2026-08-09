<?php

namespace App\Services;

use App\Models\Skill;
use App\Services\Battle\BattleActor;

class JobArtV2ActiveEvasionProvider
{
    public function rate(BattleActor $attacker, BattleActor $defender, Skill $skill, string $battleType): float
    {
        return 0.0;
    }
}
