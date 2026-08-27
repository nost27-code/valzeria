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

    public ?string $commandLastSuccessfulCategory = null;
    public ?string $commandDifferentCategoryFrom = null;
    public int $commandActivationBonus = 0;
    public int $cDesignCommandActivationBonus = 0;
    public ?string $commandActivationTargetLineage = null;
    /** @var list<int> */
    public array $commandActivationTargetRanks = [];
    public int $commandActivationRemainingOpportunities = 0;
    public bool $commandGuaranteeNextArt = false;
    public bool $commandPrioritizeCurrentArt = false;

    /** Rank5 v6 の次戦技制御。戦闘中だけ保持し、発動抽選1回で消費する。 */
    public int $rank5V6NextArtActivationBonus = 0;
    public float $rank5V6NextArtDamageMultiplier = 1.0;
    public float $rank5V6CommittedDamageMultiplier = 1.0;
    public ?string $rank5V6DifferentCategoryFrom = null;

    /** 受け流し成功後、次に使用する反撃系戦技へ適用する1回分の倍率。 */
    public float $rank5V6CounterDamageMultiplier = 1.0;

    public bool $initiativeRerollNextRound = false;
    public bool $initiativeForceFirstNextRound = false;

    /** @var array<string, true> */
    public array $reportedEligibilityGates = [];

    public bool $aimSuperRankNineSpPressureUsed = false;

    /**
     * 系譜外継承の白銀王盾を解禁する、実軽減後の1bitラッチ。
     *
     * generationは行動開始時のラッチと、その行動中に新しく成立した
     * 軽減を区別し、次の自分の行動機会でだけ失効させるために使う。
     */
    public bool $silverShieldReady = false;
    public int $silverShieldReadyGeneration = 0;

    public bool $cDesignAimMarked = false;

    /** @var array{remaining:int,last_round:int}|null */
    public ?array $musouZanshin = null;

    /** @var array{remaining:int,last_round:int,charges:int,previous_category:?string}|null */
    public ?array $overlordFormation = null;

    /** @var array{remaining:int,last_round:int,count:int,previous_category:?string,ready:bool}|null */
    public ?array $eightFormation = null;

    /** @var array{remaining:int,last_round:int,charges:int,previous_category:?string}|null */
    public ?array $royalFormation = null;

    /** @var array{remaining:int,last_round:int,charges:int}|null */
    public ?array $trackingCoordinates = null;

    public int $nightmareSelfDamage = 0;

    /** @var array{remaining:int,last_round:int,cap:int,amount:int,source_action_id:?int}|null */
    public ?array $holyWall = null;

    /** @var array{remaining:int,last_round:int}|null */
    public ?array $blackCrownReversal = null;

    /** 黒冠反転で、HP回復の解決後に与える非致死ダメージ。 */
    public int $pendingBlackCrownReversalDamage = 0;

    /** 次に受けるターン制強化の短縮・解除を無効化する残り回数。 */
    public int $immutableRhythmCharges = 0;

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
