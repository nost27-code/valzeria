<?php

namespace App\Services\Nation\Raid;

/** 既存防御pipelineを1回通した後の、Phase 1 engineへ戻す結果。 */
final readonly class NationRaidIncomingDamageApplication
{
    /** @param array<string, mixed> $defenseTrace */
    public function __construct(
        public NationRaidEnemyDamageResult $damage,
        public int $playerHp,
        public int $playerSp,
        public int $counterDamage,
        public array $defenseTrace,
    ) {}
}
