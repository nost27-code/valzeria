<?php

namespace App\Services\Nation\Raid;

/** turn-by-turn bridgeが既存player防御stateへレイドの1行動damageを渡す境界。 */
interface NationRaidIncomingDamageApplier
{
    public function apply(
        NationRaidEnemyDamageResult $damage,
        string $enemyActionId,
        int $playerHpBeforeDamage,
        int $playerSpBeforeDamage,
    ): NationRaidIncomingDamageApplication;
}
