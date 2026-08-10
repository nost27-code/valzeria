<?php

namespace App\Services;

use App\Services\Battle\BattleActor;

/**
 * FIX_NOWで復元する系譜進行効果の戦闘中専用状態。
 *
 * DB・Skill masterへ書き戻さず、BattleActorの寿命とともに破棄する。
 */
final class JobArtV2ProgressionState
{
    /** @var array<string, array{remaining:int,applied_round:int,last_round:int}> */
    public array $roundStates = [];

    public bool $pierceCrownRankFiveUsed = false;

    /** @var array<string, int> owner actor key => 0..3 */
    public array $huntingMarks = [];

    public ?string $lastActionCategory = null;
    public ?string $firstActionCategory = null;
    public ?string $observedActionCategory = null;

    /**
     * @var array<string, array{
     *   owner:BattleActor,
     *   owner_job_id:int,
     *   category:string,
     *   remaining_rounds:int,
     *   applied_round:int,
     *   last_round:int,
     *   adaptive:bool
     * }>
     */
    public array $sealReservations = [];

    /** @var array<string, array<string, int>> owner actor key => category => remaining rounds */
    public array $sealCooldowns = [];

    public bool $huntRankFiveSealSucceeded = false;
    public bool $huntCrownRetargetUsed = false;

    /** @var array<string, int> owner actor key => 0..3 */
    public array $breakMarks = [];

    /** @var array<string, int> crown-origin mark count by owner actor key */
    public array $crownBreakMarks = [];

    /** @var array<string, BattleActor> */
    public array $breakMarkOwners = [];

    public bool $zanshinAvailable = false;
    public bool $zanshinGrantedThisBattle = false;

    /**
     * @var array<string, array{
     *   owner:BattleActor,
     *   resource_key:string,
     *   remaining_gains:int,
     *   compensation_armed:bool,
     *   compensation_actions:int,
     *   compensation_seen_gain:bool,
     *   refund_points:int,
     *   created_source_action_id:int
     * }>
     */
    public array $resourceSuppressions = [];

    public bool $transmuteCrownRankNineUsed = false;

    public ?string $commandLastSuccessfulCategory = null;
    public ?string $commandDifferentCategoryFrom = null;
    public int $commandActivationBonus = 0;
    public bool $commandGuaranteeNextArt = false;
    public bool $commandPrioritizeCurrentArt = false;
    public int $commandRankNineUses = 0;

    public bool $initiativeRerollNextRound = false;
    public bool $initiativeForceFirstNextRound = false;
    public int $commandRankOneCooldownUntilRound = 0;

    public bool $aimSuperRankNineSpPressureUsed = false;

    /**
     * 系譜外継承の白銀王盾を解禁する、実軽減後の1bitラッチ。
     *
     * generationは行動開始時のラッチと、その行動中に新しく成立した
     * 軽減を区別し、次の自分の行動機会でだけ失効させるために使う。
     */
    public bool $silverShieldReady = false;
    public int $silverShieldReadyGeneration = 0;

    public function applyRoundState(string $key, int $rounds, int $round): void
    {
        $this->roundStates[$key] = [
            'remaining' => max(0, $rounds),
            'applied_round' => $round,
            'last_round' => $round,
        ];
    }

    public function hasRoundState(string $key): bool
    {
        return (int) ($this->roundStates[$key]['remaining'] ?? 0) > 0;
    }

    public function consumeRoundState(string $key): bool
    {
        if (! $this->hasRoundState($key)) {
            return false;
        }

        unset($this->roundStates[$key]);

        return true;
    }

    /** @return list<string> expired keys */
    public function advanceRoundStates(int $round): array
    {
        $expired = [];
        foreach ($this->roundStates as $key => $state) {
            if ($round <= $state['last_round']) {
                continue;
            }

            $state['last_round'] = $round;
            $state['remaining'] = max(0, $state['remaining'] - 1);
            if ($state['remaining'] <= 0) {
                unset($this->roundStates[$key]);
                $expired[] = $key;
                continue;
            }

            $this->roundStates[$key] = $state;
        }

        return $expired;
    }
}
