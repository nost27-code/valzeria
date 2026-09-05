<?php

namespace App\Services\Nation\Raid;

/** Phase 1が1ターン解決後にbridgeへ返す、player側へ同期可能な結果。 */
final readonly class NationRaidBossTurnResolution
{
    /**
     * @param  array<string, mixed>  $turnRecord
     * @param  list<string>  $appliedEnemyEffects
     */
    public function __construct(
        public int $turn,
        public array $turnRecord,
        public int $playerHp,
        public int $playerSp,
        public array $appliedEnemyEffects,
        public float $healingReductionRate,
        public int $healingReductionTurns,
        public bool $finished,
    ) {}

    public function evadedHitCount(): int
    {
        $hits = $this->turnRecord['enemy_damage']['hits'] ?? [];

        return count(array_filter(
            is_array($hits) ? $hits : [],
            static fn (mixed $hit): bool => is_array($hit) && ($hit['outcome'] ?? null) === 'evade',
        ));
    }
}
