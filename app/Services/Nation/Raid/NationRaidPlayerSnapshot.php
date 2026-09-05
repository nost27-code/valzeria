<?php

namespace App\Services\Nation\Raid;

use InvalidArgumentException;

/** 出撃開始時に全快で固定する、DB modelを持たないplayer snapshot。 */
final readonly class NationRaidPlayerSnapshot
{
    /** @var list<?string> */
    public array $bossSetExactIdentities;

    /** @var array<int, NationRaidPlayerActionSnapshot> */
    private array $actionsByTurn;

    /**
     * @param  list<?string>  $bossSetExactIdentities
     * @param  list<NationRaidPlayerActionSnapshot>  $actions
     */
    public function __construct(
        public int $maxHp,
        public int $defense,
        public int $spirit,
        public int $maxSp = 100,
        public float $enemyHitChancePercent = 90.0,
        public float $enemyEvadeChancePercent = 0.0,
        public float $enemyCriticalChancePercent = 5.0,
        public float $finalDamageReductionRate = 0.0,
        public bool $counterplayEnabled = true,
        array $bossSetExactIdentities = [],
        array $actions = [],
    ) {
        if ($maxHp < 1 || $defense < 0 || $spirit < 0 || $maxSp < 0) {
            throw new InvalidArgumentException('Raid player snapshot has an invalid ability value.');
        }
        if ($enemyHitChancePercent < 0 || $enemyHitChancePercent > 100
            || $enemyEvadeChancePercent < 0 || $enemyEvadeChancePercent > 100
            || $enemyCriticalChancePercent < 0 || $enemyCriticalChancePercent > 100
            || $finalDamageReductionRate < 0 || $finalDamageReductionRate >= 1) {
            throw new InvalidArgumentException('Raid player snapshot has an invalid rate.');
        }

        if (count($bossSetExactIdentities) > 5) {
            throw new InvalidArgumentException('Raid boss set snapshot must not exceed five slots.');
        }
        $bossSet = array_map(static function (mixed $identity): ?string {
            $normalized = trim((string) ($identity ?? ''));

            return $normalized === '' ? null : $normalized;
        }, array_values($bossSetExactIdentities));
        $this->bossSetExactIdentities = $bossSet;

        $byTurn = [];
        foreach ($actions as $action) {
            if (isset($byTurn[$action->turn])) {
                throw new InvalidArgumentException("Duplicate player action for turn {$action->turn}.");
            }
            $byTurn[$action->turn] = $action;
        }
        ksort($byTurn);
        $this->actionsByTurn = $byTurn;
    }

    public function actionForTurn(int $turn): NationRaidPlayerActionSnapshot
    {
        return $this->actionsByTurn[$turn] ?? NationRaidPlayerActionSnapshot::empty($turn);
    }
}
