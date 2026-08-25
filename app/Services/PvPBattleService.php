<?php

namespace App\Services;

use App\Models\Character;
use App\Models\ArenaRanking;
use App\Models\ArenaLog;
use App\Models\Skill;
use App\Services\CharacterNotificationService;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\BattleStatChangeLogFormatter;
use App\Services\Battle\BattleTypeAffinity;
use App\Services\Battle\DamageApplicationRequest;
use App\Services\Battle\DamageApplicationResult;
use App\Services\Battle\DamageApplicationService;
use App\Services\Battle\DamageCalculator;
use App\Services\Battle\DamageSourceType;
use App\Services\Battle\DirectAttackResolution;
use App\Services\Battle\BattleResult;
use App\Services\Battle\HitResult;
use App\Services\Battle\JobArtHitPower;
use App\Services\Battle\NullPvPRoomRule;
use App\Services\Battle\PvPBattleExecutionContext;
use App\Services\Battle\PvPBattleResolution;
use App\Services\Battle\PvPRoomRuleInterface;
use App\Services\Battle\SpeedExtraActionService;
use App\Services\Battle\SpeedBreakthroughService;
use App\Support\JobArtEffectCatalog;
use Illuminate\Support\Facades\DB;

class PvPBattleService
{
    private const PVP_HIT_AGI_FACTOR = 0.08;
    private const PVP_MIN_HIT_RATE = 84;
    private const PVP_MAX_HIT_RATE = 97;
    private const PVP_TURN_SPEED_RANDOM = 2;
    public const PVP_NORMAL_POWER_MULTIPLIER = 125;
    private const PUBLIC_RANK_UP_LOG_MAX_RANK = 50;

    protected CharacterStatusService $statusService;
    protected DamageCalculator $damageCalculator;
    protected JobArtBattleSupportService $jobArtBattleSupport;
    protected DamageApplicationService $damageApplicationService;
    protected SpeedExtraActionService $speedExtraActionService;
    protected SpeedBreakthroughService $speedBreakthroughService;

    /** @var \WeakMap<BattleState, PvPRoomRuleInterface>|null */
    private ?\WeakMap $roomRules = null;

    private ?NullPvPRoomRule $nullRoomRule = null;

    public function __construct(
        CharacterStatusService $statusService,
        DamageCalculator $damageCalculator,
        JobArtBattleSupportService $jobArtBattleSupport,
        ?DamageApplicationService $damageApplicationService = null,
        ?SpeedExtraActionService $speedExtraActionService = null,
        ?SpeedBreakthroughService $speedBreakthroughService = null,
    )
    {
        $this->statusService = $statusService;
        $this->damageCalculator = $damageCalculator;
        $this->jobArtBattleSupport = $jobArtBattleSupport;
        $this->damageApplicationService = $damageApplicationService ?? app(DamageApplicationService::class);
        $this->speedExtraActionService = $speedExtraActionService ?? app(SpeedExtraActionService::class);
        $this->speedBreakthroughService = $speedBreakthroughService ?? app(SpeedBreakthroughService::class);
        $this->roomRules = new \WeakMap();
        $this->nullRoomRule = new NullPvPRoomRule();
    }

    protected function associateRoomRule(BattleState $state, PvPRoomRuleInterface $roomRule): void
    {
        $this->roomRules ??= new \WeakMap();
        $this->roomRules[$state] = $roomRule;
        $this->jobArtBattleSupport->registerHpHealingResolver(
            $state,
            fn (
                BattleActor $actor,
                BattleState $healingState,
                Skill $skill,
                int $amount,
                bool $applyExistingModifiers,
            ): int => $this->applyResolvedHealing(
                $actor,
                $actor,
                $healingState,
                $amount,
                (int) $skill->id,
                $applyExistingModifiers,
            ),
        );
    }

    protected function roomRuleFor(BattleState $state): PvPRoomRuleInterface
    {
        return $this->roomRules !== null && isset($this->roomRules[$state])
            ? $this->roomRules[$state]
            : ($this->nullRoomRule ??= new NullPvPRoomRule());
    }

    private function ensureRoomRuleAssociation(BattleState $state): void
    {
        if ($this->roomRules !== null && isset($this->roomRules[$state])) {
            return;
        }

        $this->associateRoomRule($state, $this->roomRuleFor($state));
    }

    protected function applyResolvedHealing(
        ?BattleActor $source,
        BattleActor $target,
        BattleState $state,
        int $amount,
        int|string|null $sourceId = null,
        bool $applyExistingModifiers = true,
    ): int {
        if ($applyExistingModifiers) {
            $amount = $this->jobArtBattleSupport->modifyFieldHpHeal($target, $state, $amount);
        }

        $amount = $this->roomRuleFor($state)->modifyHealing(
            $source,
            $target,
            $state,
            $amount,
            $sourceId,
        );
        $actualHeal = $target->healHp(max(0, $amount));

        if ($applyExistingModifiers) {
            $this->jobArtBattleSupport->completeFieldHpHeal($target, $state);
        }

        return max(0, $actualHeal);
    }

    protected function applyResolvedDamage(
        ?BattleActor $source,
        BattleActor $target,
        BattleState $state,
        int $damage,
        DamageSourceType $sourceType,
        int|string|null $sourceId = null,
        ?HitResult $hitResult = null,
        int $hitIndex = 1,
        int $hitCount = 1,
        bool $isDirect = false,
        ?string $damageCategory = null,
    ): ?DamageApplicationResult {
        if ($damage <= 0) {
            $target->takeDamage($damage);

            return null;
        }

        $damage = max(0, $this->roomRuleFor($state)->modifyFinalDamage(
            $source,
            $target,
            $state,
            $damage,
            $sourceType,
            $sourceId,
            $hitIndex,
            $hitCount,
        ));
        $hpBefore = $target->hp;
        $result = null;

        if ($damage <= 0 || !$this->jobArtBattleSupport->usesDamageApplication($source, $target)) {
            $target->takeDamage($damage);
        } else {
            $result = $this->damageApplicationService->apply(new DamageApplicationRequest(
                sourceActor: $source,
                targetActor: $target,
                resolvedDamage: $damage,
                sourceType: $sourceType,
                sourceId: $sourceId,
                battleType: $state->battleType,
                hitResult: $hitResult,
                hitIndex: $hitIndex,
                hitCount: $hitCount,
                battleState: $state,
                directAttackResolution: $isDirect
                    && $source !== null
                    && $state->currentSourceActionId() !== null
                    ? DirectAttackResolution::fromDamageSource(
                        sourceActionId: $state->currentSourceActionId(),
                        attacker: $source,
                        target: $target,
                        hitResult: $hitResult,
                        damageCategory: (string) $damageCategory,
                        direct: true,
                        sourceType: $sourceType,
                    )
                    : null,
            ));
        }

        $hpAfter = $target->hp;
        $actualHpLoss = max(0, $hpBefore - $hpAfter);
        if ($source !== null) {
            $state->recordCompetitiveDamage($source, $target, $actualHpLoss);
        }
        if ($actualHpLoss > 0) {
            $this->roomRuleFor($state)->onActualHpLoss(
                $source,
                $target,
                $state,
                $actualHpLoss,
                $sourceType,
                $sourceId,
            );
        }

        return $result ?? new DamageApplicationResult(
            requestedDamage: $damage,
            hpBefore: $hpBefore,
            hpAfter: $hpAfter,
            actualHpLoss: $actualHpLoss,
            overkillDamage: max(0, $damage - $hpBefore),
            wasLethal: $target->isDead(),
            sourceType: $sourceType,
            sourceId: $sourceId,
            hitResult: $hitResult,
            hitIndex: $hitIndex,
            hitCount: $hitCount,
        );
    }

    /**
     * PvPの自動戦闘を行い、結果を返すとともに順位・ログの更新を行う
     * 
     * @return BattleResult
     */
    public function executeBattle(Character $attackerChar, Character $defenderChar): BattleResult
    {
        $resolution = $this->resolveBattle(
            $attackerChar,
            $defenderChar,
            PvPBattleExecutionContext::arena(),
        );

        $this->persistArenaBattleOutcome(
            $attackerChar,
            $defenderChar,
            $resolution->attackerWon,
        );

        app(GameplayMetricService::class)->recordJobArtBattle($attackerChar, 'pvp', $resolution->result);

        return $resolution->result;
    }

    /**
     * 順位・対戦ログ等を更新せず、PvP戦闘の解決結果だけを返す。
     */
    public function resolveBattle(
        Character $attackerChar,
        Character $defenderChar,
        ?PvPBattleExecutionContext $context = null,
    ): PvPBattleResolution {
        $context ??= PvPBattleExecutionContext::arena();
        $result = new BattleResult();

        // アタッカーアクターの生成
        $attackerStats = $this->statusService->getFinalStats($attackerChar);
        $attackerActor = new BattleActor($attackerChar->name, true, [
            'hp' => $attackerStats['max_hp'],
            'max_hp' => $attackerStats['max_hp'],
            'mp' => $attackerStats['max_mp'] ?? 0,
            'max_mp' => $attackerStats['max_mp'] ?? 0,
            'str' => $attackerStats['str'],
            'def' => $attackerStats['def'],
            'agi' => $attackerStats['agi'],
            'mag' => $attackerStats['mag'],
            'spr' => $attackerStats['spr'],
            'luk' => $attackerStats['luk'],
        ], clone $attackerChar);

        $attackerJob = $attackerChar->relationLoaded('currentJob')
            ? $attackerChar->currentJob
            : $attackerChar->currentJob()->first();
        $attackerActor->jobKey = $attackerJob?->key;
        $attackerActor->battleTypeWeights = BattleTypeAffinity::normalize($this->battleTypeWeights($attackerJob));
        $attackerActor->normalAttackType = $this->normalAttackType($attackerJob);
        $this->jobArtBattleSupport->attachBossSet($attackerActor, $attackerChar, 'champ');

        // ディフェンダーアクターの生成
        $defenderStats = $this->statusService->getFinalStats($defenderChar);
        $defenderActor = new BattleActor($defenderChar->name, false, [
            'hp' => $defenderStats['max_hp'],
            'max_hp' => $defenderStats['max_hp'],
            'mp' => $defenderStats['max_mp'] ?? 0,
            'max_mp' => $defenderStats['max_mp'] ?? 0,
            'str' => $defenderStats['str'],
            'def' => $defenderStats['def'],
            'agi' => $defenderStats['agi'],
            'mag' => $defenderStats['mag'],
            'spr' => $defenderStats['spr'],
            'luk' => $defenderStats['luk'],
        ], clone $defenderChar);

        $defenderJob = $defenderChar->relationLoaded('currentJob')
            ? $defenderChar->currentJob
            : $defenderChar->currentJob()->first();
        $defenderActor->jobKey = $defenderJob?->key;
        $defenderActor->battleTypeWeights = BattleTypeAffinity::normalize($this->battleTypeWeights($defenderJob));
        $defenderActor->normalAttackType = $this->normalAttackType($defenderJob);
        $this->jobArtBattleSupport->attachBossSet($defenderActor, $defenderChar, 'champ');

        $state = new BattleState($attackerActor, $defenderActor, 'pvp');
        $state->rankBattleMinimumDamageGuaranteeEnabled = $context->rankBattleMinimumDamageGuaranteeEnabled;
        $state->rankBattleDamageCapEnabled = $context->rankBattleDamageCapEnabled;
        $state->rankBattleBaseDamageMultiplier = $context->rankBattleBaseDamageMultiplier;
        $state->rankBattleNormalAttackPower = $context->rankBattleNormalAttackPower;
        $state->speedBreakthroughEnabled = $context->speedBreakthroughEnabled === true
            && config('battle.speed_breakthrough.enabled') === true;
        $this->associateRoomRule(
            $state,
            $context->roomRule ?? ($this->nullRoomRule ??= new NullPvPRoomRule()),
        );
        
        $state->addLog("【{$context->displayLabel}】{$attackerActor->name} が {$defenderActor->name} に勝負を挑んだ！");
        $state->addLog($this->affinityLog($attackerActor, $defenderActor));
        $state->addLog($this->affinityLog($defenderActor, $attackerActor));
        $this->roomRuleFor($state)->onBattleStart($attackerActor, $defenderActor, $state);

        while (!$state->isBattleEnded() && $state->turnCount < $state->maxTurns) {
            $state->turnCount++;
            $state->addLog("<br><br>--- ターン {$state->turnCount} ---");
            
            $usesRoleSpeed = $this->jobArtBattleSupport->usesRoleEffects($attackerActor)
                || $this->jobArtBattleSupport->usesRoleEffects($defenderActor);
            $attackerFirst = $this->resolveBaseInitiative(
                $attackerActor,
                $defenderActor,
                $state,
                $usesRoleSpeed,
            );
            if ($usesRoleSpeed) {
                $attackerFirst = $this->jobArtBattleSupport->adjustInitiative(
                    $attackerActor,
                    $defenderActor,
                    $attackerFirst,
                    fn (): bool => $this->resolveBaseInitiative(
                        $attackerActor,
                        $defenderActor,
                        $state,
                        $usesRoleSpeed,
                    ),
                );
            }

            if ($attackerFirst) {
                $this->addTurnActionHeading($state, $attackerActor, $attackerActor, true);
                $this->executeActionWithRoomRule($attackerActor, $defenderActor, $state);
                if ($attackerActor->isDead() || $defenderActor->isDead()) {
                    $this->jobArtBattleSupport->endRound($state);
                    break;
                }
                $this->addTurnActionHeading($state, $defenderActor, $attackerActor, false);
                $this->executeActionWithRoomRule($defenderActor, $attackerActor, $state);
            } else {
                $this->addTurnActionHeading($state, $defenderActor, $attackerActor, true);
                $this->executeActionWithRoomRule($defenderActor, $attackerActor, $state);
                if ($attackerActor->isDead() || $defenderActor->isDead()) {
                    $this->jobArtBattleSupport->endRound($state);
                    break;
                }
                $this->addTurnActionHeading($state, $attackerActor, $attackerActor, false);
                $this->executeActionWithRoomRule($attackerActor, $defenderActor, $state);
            }
            $this->resolveSpeedExtraAction($state, $attackerActor, $defenderActor);
            $this->jobArtBattleSupport->endRound($state);
        }

        // 戦闘終了と勝敗判定
        $isAttackerWin = false;
        
        $isTurnLimit = $state->turnCount >= $state->maxTurns;
        if ($defenderActor->isDead()) {
            // アタッカー勝利
            $state->addLog("<br><span class=\"text-black font-extrabold text-xl\">決着！{$attackerActor->name}は、{$defenderActor->name}を倒した！</span>");
            $result->result = 'victory';
            $isAttackerWin = true;
        } elseif (!$attackerActor->isDead() && $isTurnLimit && $attackerActor->hasHigherRemainingHpRatioThan($defenderActor)) {
            // ターン上限時は残り体力の割合が高い挑戦者の判定勝利
            $state->addLog("<br><span class=\"text-black font-extrabold text-xl\">判定勝利！{$attackerActor->name}が優勢のまま押し切った！</span>");
            $result->result = 'victory';
            $isAttackerWin = true;
        } else {
            // ディフェンダー勝利（または引き分けで防衛成功）
            $state->addLog("<br><span class=\"text-black font-extrabold text-xl\">決着！{$defenderActor->name}が防衛に成功した！</span>");
            $result->result = 'defeat';
            $isAttackerWin = false;
        }

        $result->logs = $state->logs;
        $result->playerHpAfter = $attackerActor->hp;
        $result->playerMpAfter = $attackerActor->mp;
        $result->turnCount = $state->turnCount;
        $result->jobArtV2Hud = $this->jobArtBattleSupport->battleHud($state);
        $result->jobArtUsage = $state->jobArtUsageFor($attackerActor);

        return new PvPBattleResolution(
            result: $result,
            attackerWon: $isAttackerWin,
            turnCount: $state->turnCount,
            attackerHp: $attackerActor->hp,
            attackerMaxHp: $attackerActor->maxHp,
            defenderHp: $defenderActor->hp,
            defenderMaxHp: $defenderActor->maxHp,
            attackerMetrics: $this->battleMetricsFor($state, $attackerActor),
            defenderMetrics: $this->battleMetricsFor($state, $defenderActor),
        );
    }

    protected function resolveBaseInitiative(
        BattleActor $attacker,
        BattleActor $defender,
        BattleState $state,
        bool $usesRoleSpeed,
    ): bool {
        $attackerSpeed = ($usesRoleSpeed ? $attacker->effectiveAgi() : $attacker->agi)
            + rand(0, self::PVP_TURN_SPEED_RANDOM);
        $defenderSpeed = ($usesRoleSpeed ? $defender->effectiveAgi() : $defender->agi)
            + rand(0, self::PVP_TURN_SPEED_RANDOM);
        $defaultAttackerFirst = $attackerSpeed >= $defenderSpeed;

        return $this->roomRuleFor($state)->modifyInitiative(
            $attacker,
            $defender,
            $state,
            $attackerSpeed,
            $defenderSpeed,
            $defaultAttackerFirst,
        );
    }

    /**
     * 双方の通常行動が終わったあと、敏捷差による追加行動を1ラウンド1回だけ解決する。
     * turnCountは進めず、endRound()もここでは呼ばない。
     */
    protected function resolveSpeedExtraAction(
        BattleState $state,
        BattleActor $attackerActor,
        BattleActor $defenderActor,
    ): void {
        if ($attackerActor->isDead() || $defenderActor->isDead()) {
            return;
        }

        foreach ([[$attackerActor, $defenderActor], [$defenderActor, $attackerActor]] as [$actor, $opponent]) {
            if (! $this->speedExtraActionService->shouldGrantExtraAction($actor, $opponent)) {
                continue;
            }

            $state->recordCompetitiveExtraAction($actor);
            $state->addLog($this->speedExtraActionService->activationLog($actor));
            $this->addExtraActionHeading($state, $actor, $attackerActor);
            $this->executeActionWithRoomRule($actor, $opponent, $state, false);

            // 追加行動から追加行動は発生させない。
            return;
        }
    }

    private function addExtraActionHeading(
        BattleState $state,
        BattleActor $actor,
        BattleActor $challenger,
    ): void {
        $isChallenger = $actor === $challenger;
        $perspectiveLabel = $isChallenger ? 'あなた' : '対戦相手';
        $colorClass = $isChallenger ? 'text-blue-700' : 'text-rose-700';
        $actorName = e($actor->name);

        $state->addLog(
            "<span class=\"{$colorClass} font-extrabold\">⚡ 追加行動　{$actorName}（{$perspectiveLabel}）の行動</span>",
        );
    }

    private function persistArenaBattleOutcome(
        Character $attackerChar,
        Character $defenderChar,
        bool $isAttackerWin,
    ): void {
        // DBトランザクションで順位変動とログ記録
        DB::transaction(function () use ($attackerChar, $defenderChar, $isAttackerWin) {
            $attackerRanking = app(ArenaNpcRankingService::class)->ensurePlayerRanking($attackerChar);
            $defenderRanking = app(ArenaNpcRankingService::class)->ensurePlayerRanking($defenderChar);

            $attackerOldRank = $attackerRanking->rank;
            $defenderOldRank = $defenderRanking->rank;
            $attackerNewRank = $attackerOldRank;
            $defenderNewRank = $defenderOldRank;

            if ($isAttackerWin) {
                $attackerRanking->wins += 1;
                $defenderRanking->losses += 1;

                // 格上に勝った場合は相手の順位を奪い、間の順位を1つずつ下げる。
                if ($defenderOldRank < $attackerOldRank) {
                    $targetRank = (int) $defenderOldRank;

                    $temporaryRank = -1 * (int) $attackerRanking->id;
                    $attackerRanking->rank = $temporaryRank;
                    $attackerRanking->save();

                    app(ArenaNpcRankingService::class)->shiftCombinedRanksDown(
                        $targetRank,
                        $attackerOldRank - 1,
                        (int) $attackerChar->id
                    );
                    $defenderRanking->refresh();

                    if ((int) $defenderRanking->rank !== (int) $defenderOldRank
                        && $defenderRanking->character
                        && (int) $defenderRanking->character->id !== (int) $attackerChar->id
                    ) {
                        app(CharacterNotificationService::class)->create(
                            $defenderRanking->character,
                            'arena',
                            'arena_rank_down',
                            'ランク戦順位が低下しました',
                            "{$attackerChar->name}さんの勝利により、闘技場順位が{$defenderOldRank}位から{$defenderRanking->rank}位に下がりました。",
                            '順位を見る',
                            route('colosseum.ranking'),
                            [
                                'attacker_id' => (int) $attackerChar->id,
                                'old_rank' => (int) $defenderOldRank,
                                'new_rank' => (int) $defenderRanking->rank,
                            ],
                            85
                        );
                    }

                    $attackerRanking->rank = $targetRank;
                }
            } else {
                $attackerRanking->losses += 1;
                $defenderRanking->wins += 1;
                // 敗北の場合は順位変動なし
            }

            $attackerRanking->save();
            $defenderRanking->save();

            $attackerNewRank = $attackerRanking->rank;
            $defenderNewRank = $defenderRanking->rank;

            ArenaLog::create([
                'attacker_id' => $attackerChar->id,
                'defender_id' => $defenderChar->id,
                'is_attacker_win' => $isAttackerWin,
                'attacker_old_rank' => $attackerOldRank,
                'attacker_new_rank' => $attackerNewRank,
                'defender_old_rank' => $defenderOldRank,
                'defender_new_rank' => $defenderNewRank,
            ]);

            $this->publishArenaRankPublicLogs(
                $attackerChar,
                $isAttackerWin,
                (int) $attackerOldRank,
                (int) $attackerNewRank
            );
        });
    }

    private function publishArenaRankPublicLogs(
        Character $attackerChar,
        bool $isAttackerWin,
        int $attackerOldRank,
        int $attackerNewRank
    ): void {
        if (!$isAttackerWin || $attackerNewRank >= $attackerOldRank) {
            return;
        }

        $logService = app(PublicLogService::class);
        if ($attackerNewRank <= self::PUBLIC_RANK_UP_LOG_MAX_RANK) {
            $logService->addLog(
                'arena',
                "【闘技場】{$attackerChar->name}さんが強敵を破り、{$attackerOldRank}位から{$attackerNewRank}位へ駆け上がりました！",
                $attackerChar,
                2
            );
        }

        if ($attackerOldRank > 10 && $attackerNewRank <= 10) {
            $logService->addLog(
                'arena',
                "【闘技場】{$attackerChar->name}さんが闘技場番付TOP10入りを果たしました！",
                $attackerChar,
                3
            );
        }
    }

    /**
     * 行動（通常攻撃またはスキル攻撃）
     */
    private function addTurnActionHeading(
        BattleState $state,
        BattleActor $actor,
        BattleActor $challenger,
        bool $isFirst,
    ): void {
        $orderLabel = $isFirst ? '先手' : '後手';
        $orderMark = $isFirst ? '◆' : '◇';
        $isChallenger = $actor === $challenger;
        $perspectiveLabel = $isChallenger ? 'あなた' : '対戦相手';
        $colorClass = $isChallenger ? 'text-blue-700' : 'text-rose-700';
        $actorName = e($actor->name);

        $state->addLog(
            "<br><span class=\"{$colorClass} font-extrabold\">{$orderMark} {$orderLabel}　{$actorName}（{$perspectiveLabel}）の行動</span>",
        );
    }

    protected function executeActionWithRoomRule(
        BattleActor $actor,
        BattleActor $opponent,
        BattleState $state,
        bool $tickCooldowns = true,
    ): void {
        $this->executeAction($actor, $opponent, $state, $tickCooldowns);
        $this->roomRuleFor($state)->onActionEnd($actor, $opponent, $state);
    }

    protected function executeAction(
        BattleActor $attacker,
        BattleActor $defender,
        BattleState $state,
        bool $tickCooldowns = true,
    ): void
    {
        $this->ensureRoomRuleAssociation($state);
        $this->jobArtBattleSupport->beginAction($attacker, $state);
        $state->beginCompetitiveAction($attacker, $defender);
        if ($state->speedBreakthroughEnabled) {
            $state->snapshotSpeedBreakthrough(
                $attacker,
                $defender,
                $this->speedBreakthroughService->nominalRate($attacker, $defender),
            );
        }

        try {
        $attacker->isDefending = false;
        $attacker->damageReductionRate = 0;

        $usedSkill = false;
        if ($tickCooldowns) {
            $this->jobArtBattleSupport->tickCooldowns($state, $attacker);
        }
        $jobArt = $this->jobArtBattleSupport->selectForTurn($attacker, $state);
        if ($jobArt && $this->jobArtBattleSupport->consumeAndMarkUse(
            $attacker,
            $state,
            $jobArt,
            $this->jobArtBattleSupport->activationLog($attacker, $defender, $jobArt),
        )) {
            $executionSkill = $this->jobArtBattleSupport->skillForExecution($attacker, $jobArt, $state, $defender);
            $hitResult = $this->jobArtBattleSupport->resolveHit($attacker, $defender, $executionSkill, $state->battleType, $state);
            if ($hitResult !== null && !$hitResult->landed()) {
                $state->addLog($this->jobArtBattleSupport->resolutionFailureLog($jobArt, $hitResult));
            }
            $this->executeSkillAction(
                $attacker,
                $defender,
                $state,
                $executionSkill,
                false,
                $hitResult,
            );
            $this->jobArtBattleSupport->completeJobArtCast($attacker, $state, $jobArt, $hitResult, $defender);
            $usedSkill = true;
        }

        if (!$usedSkill) {
            $this->executeNormalAttack($attacker, $defender, $state, $state->rankBattleNormalAttackPower);
        }
        } finally {
            $this->jobArtBattleSupport->finishAction($attacker, $state);
        }
    }

    /**
     * 通常の物理攻撃処理
     */
    protected function executeNormalAttack(BattleActor $attacker, BattleActor $defender, BattleState $state, int $powerMultiplier = 100): void
    {
        $this->jobArtBattleSupport->markNormalAttackAction($attacker, $state);
        $state->markCompetitiveDamageAction($attacker, $defender, DamageSourceType::NORMAL_ATTACK);

        if (!$this->damageCalculator->isHit(
            $attacker,
            $defender,
            100,
            self::PVP_HIT_AGI_FACTOR,
            self::PVP_MIN_HIT_RATE,
            self::PVP_MAX_HIT_RATE,
            $this->jobArtBattleSupport->fieldAccuracyDelta($attacker, $state),
        )) {
            $state->addLog("{$attacker->name} の攻撃！……しかし、{$defender->name} はかわした！");
            $this->jobArtBattleSupport->recordNormalAttackResolution($attacker, $defender, $state, HitResult::MISS, false);
            return;
        }

        $attackType = $attacker->usesMagForNormalAttack() ? 'magical' : 'physical';
        $isCrit = $this->damageCalculator->isRankBattleCritical($attacker, $defender);
        $affinityMultiplier = $this->affinityMultiplier($attacker, $defender);
        $statOverrides = $this->roomRuleFor($state)->modifyDamageStatOverrides(
            $attacker,
            $defender,
            $state,
            $attackType,
            DamageSourceType::NORMAL_ATTACK,
            null,
            ['attack' => null, 'def' => null, 'spr' => null],
        );
        $breakthroughRates = $this->speedBreakthroughRates($attacker, $defender, $state, 0.0);
        $damage = $this->damageCalculator->calculateRankBattleDamage(
            $attacker,
            $defender,
            $attackType,
            $powerMultiplier,
            $isCrit,
            $affinityMultiplier,
            $statOverrides['attack'],
            $statOverrides['def'],
            $statOverrides['spr'],
            minimumDamageGuaranteeEnabled: $state->rankBattleMinimumDamageGuaranteeEnabled,
            damageCapEnabled: $state->rankBattleDamageCapEnabled,
            baseDamageMultiplier: $state->rankBattleBaseDamageMultiplier,
            additionalDefenseIgnoreRate: $breakthroughRates['additional_ignore_rate'],
        );
        $damage = $this->jobArtBattleSupport->modifyFieldDamage($attacker, $state, $damage, DamageSourceType::NORMAL_ATTACK);
        $damageResult = $this->applyResolvedDamage(
            $attacker,
            $defender,
            $state,
            $damage,
            DamageSourceType::NORMAL_ATTACK,
            null,
            HitResult::HIT,
            1,
            1,
            true,
            $attackType,
        );
        $damage = $damageResult?->requestedDamage ?? $damage;
        $critText = $isCrit ? "<span class=\"text-orange-500 font-bold\">【痛恨の一撃！】</span>" : "";
        $damageClass = $attackType === 'magical' ? 'text-purple-600' : 'text-red-600';
        $state->addDamageLog("{$attacker->name} の攻撃！ {$critText} {$defender->name} に <span class=\"{$damageClass} font-extrabold text-lg\">{$damage}</span> のダメージ！");
        $this->jobArtBattleSupport->recordNormalAttackResolution($attacker, $defender, $state, HitResult::HIT, false);
        $this->logGutsIfTriggered($defender, $state);
    }

    protected function executePhysicalAttack(BattleActor $attacker, BattleActor $defender, BattleState $state, int $powerMultiplier = 100): void
    {
        $state->markCompetitiveDamageAction($attacker, $defender, DamageSourceType::NORMAL_ATTACK);
        if (!$this->damageCalculator->isHit(
            $attacker,
            $defender,
            100,
            self::PVP_HIT_AGI_FACTOR,
            self::PVP_MIN_HIT_RATE,
            self::PVP_MAX_HIT_RATE,
            $this->jobArtBattleSupport->fieldAccuracyDelta($attacker, $state),
        )) {
            $state->addLog("{$attacker->name} の攻撃！……しかし、{$defender->name} はかわした！");
            return;
        }

        $isCrit = $this->damageCalculator->isRankBattleCritical($attacker, $defender);
        $affinityMultiplier = $this->affinityMultiplier($attacker, $defender);
        $statOverrides = $this->roomRuleFor($state)->modifyDamageStatOverrides(
            $attacker,
            $defender,
            $state,
            'physical',
            DamageSourceType::NORMAL_ATTACK,
            null,
            ['attack' => null, 'def' => null, 'spr' => null],
        );
        $breakthroughRates = $this->speedBreakthroughRates($attacker, $defender, $state, 0.0);
        $damage = $this->damageCalculator->calculateRankBattleDamage(
            $attacker,
            $defender,
            'physical',
            $powerMultiplier,
            $isCrit,
            $affinityMultiplier,
            $statOverrides['attack'],
            $statOverrides['def'],
            $statOverrides['spr'],
            minimumDamageGuaranteeEnabled: $state->rankBattleMinimumDamageGuaranteeEnabled,
            damageCapEnabled: $state->rankBattleDamageCapEnabled,
            baseDamageMultiplier: $state->rankBattleBaseDamageMultiplier,
            additionalDefenseIgnoreRate: $breakthroughRates['additional_ignore_rate'],
        );
        $damage = $this->jobArtBattleSupport->modifyFieldDamage($attacker, $state, $damage, DamageSourceType::NORMAL_ATTACK);
        $damageResult = $this->applyResolvedDamage(
            $attacker,
            $defender,
            $state,
            $damage,
            DamageSourceType::NORMAL_ATTACK,
            null,
            HitResult::HIT,
            1,
            1,
            true,
            'physical',
        );
        $damage = $damageResult?->requestedDamage ?? $damage;
        $critText = $isCrit ? "<span class=\"text-orange-500 font-bold\">【痛恨の一撃！】</span>" : "";
        $state->addDamageLog("{$attacker->name} の攻撃！ {$critText} {$defender->name} に <span class=\"text-red-600 font-extrabold text-lg\">{$damage}</span> のダメージ！");
        $this->logGutsIfTriggered($defender, $state);
    }

    /**
     * スキル（必殺技）の実行
     */
    protected function executeSkillAction(
        BattleActor $attacker,
        BattleActor $defender,
        BattleState $state,
        Skill $skill,
        bool $addOpeningLog = true,
        ?HitResult $jobArtHitResult = null,
    ): void
    {
        $this->jobArtBattleSupport->markSkillAction($attacker, $state, $skill);
        $state->markCompetitiveDamageAction(
            $attacker,
            $defender,
            $skill->isJobArt() ? DamageSourceType::JOB_ART : DamageSourceType::JOB_SKILL,
        );
        $damageType = $this->resolveSkillDamageType($attacker, $skill);
        $damageClass = $damageType === 'magical' ? 'text-purple-600' : 'text-red-600';
        if ($addOpeningLog) {
            $state->addLog("<span class=\"text-blue-600 font-bold\">【必殺技】{$attacker->name} の必殺技、{$skill->name} が発動！</span>");
        }
        if ($this->jobArtBattleSupport->isFieldOnlyArt($attacker, $state, $skill)) {
            return;
        }

        $applyTargetEffects = $jobArtHitResult === null || $jobArtHitResult->landed();
        $hitCount = max(1, (int) $skill->hit_count);
        if ((int) $skill->hit_count === 0 && in_array($damageType, ['heal', 'support'], true)) {
            $hitCount = 1; 
        }
        if ($applyTargetEffects && (int) $skill->extra_hit_chance_percent > 0 && random_int(1, 100) <= (int) $skill->extra_hit_chance_percent) {
            $hitCount++;
        }

        $totalPower = max(0, (int) round((float) $skill->power_multiplier * 100));
        if ((float) $skill->luk_power_rate > 0) {
            $lukPowerContribution = (int) floor(
                $attacker->effectiveLuk() * (float) $skill->luk_power_rate,
            );
            $lukPowerContribution = $this->roomRuleFor($state)->modifyLukPowerContribution(
                $attacker,
                $defender,
                $state,
                $skill,
                $lukPowerContribution,
            );
            $totalPower += $lukPowerContribution;
        }
        $hitPowers = $skill->isJobArt()
            ? JobArtHitPower::split($totalPower, $hitCount)
            : array_fill(0, $hitCount, $totalPower);
        $dealsDamage = ! $skill->isJobArt()
            || JobArtEffectCatalog::dealsDamage((string) $skill->effect_template);

        $totalDamage = 0;
        for ($i = 0; $applyTargetEffects && $i < $hitCount; $i++) {
            $damage = 0;
            // This route has no existing Job Art critical roll. Role-diversity
            // bonuses may adjust an existing roll, but must not add RNG here.
            $isCrit = false;
            $skillPowerInt = $hitPowers[$i];

            $overrides = $this->jobArtBattleSupport->defenseOverrides($attacker, $defender, $state, $skill);
            $statOverrides = $this->jobArtBattleSupport->damageStatOverrides($attacker, $defender, $skill);
            $existingIgnoreRate = (float) ($statOverrides['applied_ignore_rate'] ?? 0.0);
            $statOverrides = [
                'attack' => $statOverrides['attack'],
                'def' => $statOverrides['def'] ?? $overrides['def'],
                'spr' => $statOverrides['spr'] ?? $overrides['spr'],
            ];
            if ($damageType === 'hybrid') {
                $statOverrides['attack'] = $attacker->hybridAttackPower(
                    (string) $skill->hybrid_scaling,
                    $this->jobArtBattleSupport->usesRoleEffects($attacker),
                );
            }

            if ($dealsDamage && (float) $skill->power_multiplier > 0) {
                $affinityMultiplier = $this->affinityMultiplier($attacker, $defender);
                $calculationAttackType = match ($damageType) {
                    'magical' => 'magical',
                    'hybrid' => 'hybrid',
                    default => 'physical',
                };
                $statOverrides = $this->roomRuleFor($state)->modifyDamageStatOverrides(
                    $attacker,
                    $defender,
                    $state,
                    $calculationAttackType,
                    $skill->isJobArt() ? DamageSourceType::JOB_ART : DamageSourceType::JOB_SKILL,
                    $skill,
                    $statOverrides,
                );
                $overrideAtk = $statOverrides['attack'];
                $overrideDef = $statOverrides['def'];
                $overrideSpr = $statOverrides['spr'];
                $breakthroughRates = $this->speedBreakthroughRates(
                    $attacker,
                    $defender,
                    $state,
                    $existingIgnoreRate,
                );
                if (in_array($damageType, ['physical', 'gold', 'drop', 'support'], true)) {
                    $damage = $this->damageCalculator->calculateRankBattleDamage(
                        $attacker,
                        $defender,
                        'physical',
                        $skillPowerInt,
                        $isCrit,
                        $affinityMultiplier,
                        $overrideAtk,
                        $overrideDef,
                        $overrideSpr,
                        true,
                        $hitCount,
                        $state->rankBattleMinimumDamageGuaranteeEnabled,
                        $state->rankBattleDamageCapEnabled,
                        baseDamageMultiplier: $state->rankBattleBaseDamageMultiplier,
                        additionalDefenseIgnoreRate: $breakthroughRates['additional_ignore_rate'],
                    );
                } elseif ($damageType === 'magical') {
                    $damage = $this->damageCalculator->calculateRankBattleDamage(
                        $attacker,
                        $defender,
                        'magical',
                        $skillPowerInt,
                        $isCrit,
                        $affinityMultiplier,
                        $overrideAtk,
                        $overrideDef,
                        $overrideSpr,
                        true,
                        $hitCount,
                        $state->rankBattleMinimumDamageGuaranteeEnabled,
                        $state->rankBattleDamageCapEnabled,
                        baseDamageMultiplier: $state->rankBattleBaseDamageMultiplier,
                        additionalDefenseIgnoreRate: $breakthroughRates['additional_ignore_rate'],
                    );
                } elseif ($damageType === 'hybrid') {
                    $damage = $this->damageCalculator->calculateRankBattleDamage(
                        $attacker,
                        $defender,
                        'physical',
                        $skillPowerInt,
                        $isCrit,
                        $affinityMultiplier,
                        $overrideAtk,
                        $overrideDef,
                        $overrideSpr,
                        true,
                        $hitCount,
                        $state->rankBattleMinimumDamageGuaranteeEnabled,
                        $state->rankBattleDamageCapEnabled,
                        baseDamageMultiplier: $state->rankBattleBaseDamageMultiplier,
                        additionalDefenseIgnoreRate: $breakthroughRates['additional_ignore_rate'],
                    );
                }
            }

            if ($damage > 0) {
                $damage = $this->jobArtBattleSupport->modifyFieldDamage(
                    $attacker,
                    $state,
                    $damage,
                    $skill->isJobArt() ? DamageSourceType::JOB_ART : DamageSourceType::JOB_SKILL,
                );
                if ($skill->isJobArt()) {
                    $damage = $this->jobArtBattleSupport->modifyJobArtDamage($attacker, $state, $skill, $damage);
                }
                $damageResult = $this->applyResolvedDamage(
                    $attacker,
                    $defender,
                    $state,
                    $damage,
                    $skill->isJobArt() ? DamageSourceType::JOB_ART : DamageSourceType::JOB_SKILL,
                    (int) $skill->id,
                    $jobArtHitResult,
                    $i + 1,
                    $hitCount,
                    true,
                    $damageType === 'magical' ? 'magical' : 'physical',
                );
                $damage = $damageResult?->requestedDamage ?? $damage;
                $totalDamage += $damage;
                $state->addDamageLog("{$defender->name} に <span class=\"{$damageClass} font-extrabold text-lg\">{$damage}</span> のダメージ！");
                $this->logGutsIfTriggered($defender, $state);
            }

            if ($attacker->isDead() || $defender->isDead()) break;
        }

        if ($skill->isJobArt()) {
            $this->applyJobArtTemplateEffects(
                $attacker,
                $defender,
                $state,
                $skill,
                $totalDamage,
                $applyTargetEffects,
                $damageType,
            );
        }

        if ($skill->heal_percent > 0) {
            $healAmount = (int)($attacker->maxHp * ($skill->heal_percent / 100));
            $actualHeal = $this->applyResolvedHealing(
                $attacker,
                $attacker,
                $state,
                $healAmount,
                (int) $skill->id,
            );
            $state->addLog("<span class=\"text-green-600 font-bold\">{$attacker->name} の傷が {$actualHeal} 回復した！</span>");
        }

        if ($skill->mp_recover_percent > 0 && $attacker->maxMp > 0) {
            $mpHealAmount = (int)($attacker->maxMp * ($skill->mp_recover_percent / 100));
            $attacker->mp = min($attacker->maxMp, $attacker->mp + $mpHealAmount);
            $state->addLog("<span class=\"text-blue-500 font-bold\">{$attacker->name} はSPを {$mpHealAmount} 回復した！</span>");
        }

        if ($skill->self_damage_percent > 0) {
            $selfDamage = (int)($attacker->maxHp * ($skill->self_damage_percent / 100));
            $this->applyResolvedDamage(
                $attacker,
                $attacker,
                $state,
                $selfDamage,
                DamageSourceType::RECOIL,
                (int) $skill->id,
            );
            $state->addLog("<span class=\"text-purple-600 font-bold\">反動により、{$attacker->name} は {$selfDamage} のダメージを受けた！</span>");
            $this->jobArtBattleSupport->recordSelfDamage($attacker, $state, $selfDamage);
            $this->logGutsIfTriggered($attacker, $state);
        }

        if ($applyTargetEffects) {
            $this->applyStructuredDebuffs($attacker, $defender, $state, $skill);
        }

        if ((int) $skill->damage_reduction_percent > 0 && ! ($skill->isJobArt() && in_array((string) $skill->effect_template, ['GUARD_BARRIER', 'DAMAGE_GUARD_BARRIER'], true))) {
            $state->addLog("{$attacker->name} は次の被ダメージを軽減する構えをとった！");
            $attacker->damageReductionRate = max($attacker->damageReductionRate, min(50, (int) $skill->damage_reduction_percent));
        }
        
        if (!$skill->isJobArt() && (int) $skill->self_buff_percent > 0) {
            $rate = (int) $skill->self_buff_percent / 100;
            $beforeStr = $attacker->str;
            $beforeMag = $attacker->mag;
            $attacker->str = min((int) floor($attacker->baseStr * 1.5), $attacker->str + (int) floor($attacker->baseStr * $rate));
            $attacker->mag = min((int) floor($attacker->baseMag * 1.5), $attacker->mag + (int) floor($attacker->baseMag * $rate));
            $state->addLog(BattleStatChangeLogFormatter::fromValues($attacker->name, [
                ['label' => 'ATK', 'before' => $beforeStr, 'after' => $attacker->str],
                ['label' => 'MAG', 'before' => $beforeMag, 'after' => $attacker->mag],
            ], true));
        }
    }

    protected function resolveSkillDamageType(BattleActor $attacker, Skill $skill): string
    {
        return JobArtEffectCatalog::resolveDamageType($skill, $attacker->usesMagForNormalAttack());
    }

    private function applyJobArtTemplateEffects(
        BattleActor $attacker,
        BattleActor $defender,
        BattleState $state,
        Skill $skill,
        int $totalDamage,
        bool $applyTargetEffects = true,
        string $damageType = '',
    ): void {
        $template = (string) $skill->effect_template;
        $power = max(1, (int) ($skill->power ?: 100));

        if (in_array($template, ['HEAL', 'HEAL_CLEANSE'], true)) {
            $healingSpr = $this->jobArtBattleSupport->usesRoleEffects($attacker)
                ? $attacker->effectiveSpr()
                : $attacker->spr;
            $heal = max(1, (int) floor($healingSpr * ($power / 100)));
            $actualHeal = $this->applyResolvedHealing(
                $attacker,
                $attacker,
                $state,
                $heal,
                (int) $skill->id,
            );
            $state->addLog("<span class=\"text-emerald-600 font-bold\">HPが {$actualHeal} 回復した！</span>");
        }

        if ($template === 'DRAIN' && $totalDamage > 0 && (float) $skill->drain_hp_rate > 0) {
            $heal = max(1, (int) floor($totalDamage * (float) $skill->drain_hp_rate));
            $actualHeal = $this->applyResolvedHealing(
                $attacker,
                $attacker,
                $state,
                $heal,
                (int) $skill->id,
            );
            $state->addLog("<span class=\"text-emerald-600 font-bold\">与えた力を吸収し、HPが {$actualHeal} 回復した！</span>");
        }

        if ($template === 'GUTS') {
            $attacker->gutsReady = true;
            $state->addLog("<span class=\"text-orange-700 font-bold\">{$attacker->name} は一度だけ踏みとどまる覚悟を固めた！</span>");
        }


        if (in_array($template, ['GUARD_BARRIER', 'DAMAGE_GUARD_BARRIER'], true)) {
            $rate = (float) ($attacker->jobArtRates[(int) $skill->id] ?? 1.0);
            $reduction = $this->jobArtGuardReduction($skill, $rate);
            $attacker->damageReductionRate = max($attacker->damageReductionRate, min(50, $reduction));
            $state->addLog("<span class=\"text-blue-700 font-bold\">{$attacker->name} は次の被ダメージを {$reduction}% 軽減する！</span>");
        }

        if (in_array($template, ['SELF_BUFF', 'DAMAGE_BUFF', 'MAGICAL_DAMAGE_BUFF'], true)) {
            $shared = $this->jobArtBattleSupport->applySharedSelfBuff(
                $attacker,
                $state,
                $skill,
                $template === 'DAMAGE_BUFF' ? $damageType : null,
            );
            if ($shared !== null) {
                if (! $shared['exact_log_written']) {
                    $this->logStatChange(
                        $state,
                        $attacker->name,
                        $shared['main_label'],
                        $shared['main_before'],
                        $shared['main_after'],
                        $shared['sub_label'],
                        $shared['sub_before'],
                        $shared['sub_after'],
                        true,
                    );
                }
            } else {
                $isMagicalDamageBuff = $template === 'MAGICAL_DAMAGE_BUFF'
                    || ($template === 'DAMAGE_BUFF' && match ($damageType) {
                        'magical' => true,
                        'physical' => false,
                        default => $attacker->usesMagForNormalAttack(),
                    });
                if ($isMagicalDamageBuff) {
                    $beforeMag = $attacker->mag;
                    $beforeSpr = $attacker->spr;
                    $attacker->mag = min((int) floor($attacker->baseMag * 1.5), $attacker->mag + max(1, (int) floor($attacker->baseMag * 0.10)));
                    $attacker->spr = min((int) floor($attacker->baseSpr * 1.5), $attacker->spr + max(1, (int) floor($attacker->baseSpr * 0.05)));
                    $this->logStatChange($state, $attacker->name, 'MAG', $beforeMag, $attacker->mag, 'SPR', $beforeSpr, $attacker->spr, true);
                } elseif ($template === 'DAMAGE_BUFF') {
                    $beforeStr = $attacker->str;
                    $beforeDef = $attacker->def;
                    $attacker->str = min((int) floor($attacker->baseStr * 1.5), $attacker->str + max(1, (int) floor($attacker->baseStr * 0.10)));
                    $attacker->def = min((int) floor($attacker->baseDef * 1.5), $attacker->def + max(1, (int) floor($attacker->baseDef * 0.05)));
                    $this->logStatChange($state, $attacker->name, 'ATK', $beforeStr, $attacker->str, 'DEF', $beforeDef, $attacker->def, true);
                } else {
                    $beforeStr = $attacker->str;
                    $beforeMag = $attacker->mag;
                    $attacker->str = min((int) floor($attacker->baseStr * 1.5), $attacker->str + max(1, (int) floor($attacker->baseStr * 0.10)));
                    $attacker->mag = min((int) floor($attacker->baseMag * 1.5), $attacker->mag + max(1, (int) floor($attacker->baseMag * 0.10)));
                    $this->logStatChange($state, $attacker->name, 'ATK', $beforeStr, $attacker->str, 'MAG', $beforeMag, $attacker->mag, true);
                }
            }
        }

        if ($applyTargetEffects && in_array($template, ['ENEMY_DEBUFF', 'DAMAGE_DEBUFF'], true) && !$this->hasStructuredDebuff($skill)) {
            $beforeDef = $defender->def;
            $beforeSpr = $defender->spr;
            $defender->def = max(1, $defender->def - max(1, (int) floor($defender->baseDef * 0.10)));
            $defender->spr = max(1, $defender->spr - max(1, (int) floor($defender->baseSpr * 0.05)));
            $this->logStatChange($state, $defender->name, 'DEF', $beforeDef, $defender->def, 'SPR', $beforeSpr, $defender->spr, false);
        }

        if ($applyTargetEffects && $template === 'TIME_CONTROL_CURRENT_ONLY' && !$this->hasStructuredDebuff($skill)) {
            $rate = (int) $skill->enemy_spd_down_percent > 0 ? (int) $skill->enemy_spd_down_percent / 100 : 0.10;
            $before = $defender->agi;
            $defender->agi = max(1, $defender->agi - max(1, (int) floor($defender->baseAgi * $rate)));
            $state->addLog(BattleStatChangeLogFormatter::fromValues($defender->name, [
                ['label' => 'SPD', 'before' => $before, 'after' => $defender->agi],
            ], false));
        }
    }

    private function logStatChange(
        BattleState $state,
        string $actorName,
        string $mainLabel,
        int $mainBefore,
        int $mainAfter,
        string $subLabel,
        int $subBefore,
        int $subAfter,
        bool $isBuff
    ): void {
        $state->addLog(BattleStatChangeLogFormatter::fromValues($actorName, [
            ['label' => $mainLabel, 'before' => $mainBefore, 'after' => $mainAfter],
            ['label' => $subLabel, 'before' => $subBefore, 'after' => $subAfter],
        ], $isBuff));
    }

    private function hasStructuredDebuff(Skill $skill): bool
    {
        return (int) $skill->enemy_atk_down_percent > 0
            || (int) $skill->enemy_mag_down_percent > 0
            || (int) $skill->enemy_def_down_percent > 0
            || (int) $skill->enemy_spr_down_percent > 0
            || (int) $skill->enemy_spd_down_percent > 0;
    }

    private function jobArtGuardReduction(Skill $skill, float $rate = 1.0): int
    {
        if ((int) $skill->damage_reduction_percent > 0) {
            return min(50, max(1, (int) floor((int) $skill->damage_reduction_percent * $rate)));
        }

        // powerは呼び出し元でskillForExecution()により既に継承倍率でスケール済み
        return min(50, max(10, (int) floor(max(80, (int) ($skill->power ?: 100)) / 10)));
    }

   private function applyStructuredDebuffs(
       BattleActor $attacker,
       BattleActor $defender,
       BattleState $state,
       Skill $skill,
   ): void
   {
        if ($state->harmfulAttachedEffectsBlockedFor($defender)) {
            return;
        }

       $timed = $this->jobArtBattleSupport->applyTimedStructuredDebuffs(
            $attacker,
            $defender,
            $state,
            $skill,
        );
        if ($timed !== null) {
            foreach ($timed['changes'] as $change) {
                $state->addLog(BattleStatChangeLogFormatter::fromPercentages(
                    $defender->name,
                    [['label' => $change['label'], 'percent' => $change['percent']]],
                    false,
                    $timed['duration_turns'].'ターン',
                ));
            }

            return;
        }

        $debuffs = [
            'enemy_atk_down_percent' => ['prop' => 'str', 'base' => 'baseStr', 'label' => '攻撃'],
            'enemy_mag_down_percent' => ['prop' => 'mag', 'base' => 'baseMag', 'label' => '魔力'],
            'enemy_def_down_percent' => ['prop' => 'def', 'base' => 'baseDef', 'label' => '防御'],
            'enemy_spr_down_percent' => ['prop' => 'spr', 'base' => 'baseSpr', 'label' => '精神'],
            'enemy_spd_down_percent' => ['prop' => 'agi', 'base' => 'baseAgi', 'label' => '敏捷'],
        ];

        foreach ($debuffs as $field => $config) {
            $effect = (int) ($skill->{$field} ?? 0);
            if ($effect <= 0) {
                continue;
            }

            $prop = $config['prop'];
            $base = $config['base'];
            $defender->{$prop} = max(1, $defender->{$prop} - (int) floor($defender->{$base} * ($effect / 100)));
            $state->addLog(BattleStatChangeLogFormatter::fromPercentages(
                $defender->name,
                [['label' => $config['label'], 'percent' => $effect]],
                false,
            ));
        }
    }

    private function battleTypeWeights(?object $job): array
    {
        return [
            'physical' => (float) ($job?->affinity_physical ?? 1.0),
            'speed' => (float) ($job?->affinity_speed ?? 0.0),
            'magical' => (float) ($job?->affinity_magical ?? 0.0),
        ];
    }

    private function normalAttackType(?object $job): string
    {
        $type = strtolower(trim((string) ($job?->normal_attack_type ?? '')));
        if (in_array($type, ['physical', 'magical', 'adaptive'], true)) {
            return $type;
        }

        return 'physical';
    }

    private function affinityMultiplier(BattleActor $attacker, BattleActor $defender): float
    {
        return BattleTypeAffinity::multiplier($attacker->battleTypeWeights, $defender->battleTypeWeights);
    }

    private function logGutsIfTriggered(BattleActor $actor, BattleState $state): void
    {
        if (!$actor->gutsJustTriggered) {
            return;
        }

        $actor->gutsJustTriggered = false;
        $state->addLog("<span class=\"text-orange-700 font-extrabold\">{$actor->name} は不屈の精神で致死ダメージを耐えた！（HP1）</span>");
    }

    /**
     * @return array{
     *     nominal_rate: float,
     *     existing_ignore_rate: float,
     *     combined_ignore_rate: float,
     *     additional_ignore_rate: float
     * }
     */
    private function speedBreakthroughRates(
        BattleActor $attacker,
        BattleActor $defender,
        BattleState $state,
        float $existingIgnoreRate,
    ): array {
        $none = [
            'nominal_rate' => 0.0,
            'existing_ignore_rate' => $existingIgnoreRate,
            'combined_ignore_rate' => $existingIgnoreRate,
            'additional_ignore_rate' => 0.0,
        ];
        if (! $state->speedBreakthroughEnabled) {
            return $none;
        }

        $snapshot = $state->speedBreakthroughRates($attacker, $defender);
        if ($snapshot !== null) {
            return $snapshot;
        }

        $rates = $this->speedBreakthroughService->rates(
            $state->speedBreakthroughNominalRate($attacker, $defender),
            $existingIgnoreRate,
        );
        $state->recordSpeedBreakthroughRates($attacker, $defender, $rates);

        if ($rates['additional_ignore_rate'] >= 0.01
            && $state->claimSpeedBreakthroughLog($attacker, $defender)
        ) {
            $rate = number_format($rates['additional_ignore_rate'] * 100, 1);
            $state->addLog(
                "<span class=\"text-cyan-700 font-bold\">【敏捷突破】{$attacker->name} の速さが {$defender->name} の守りを突き抜けた！（守りをさらに{$rate}%突破）</span>",
            );
        }

        return $rates;
    }

    /** @return array<string, mixed> */
    private function battleMetricsFor(BattleState $state, BattleActor $actor): array
    {
        $metrics = $state->competitiveMetricsFor($actor);

        return array_merge($metrics, [
            'action_count' => $state->competitiveActionCountFor($actor),
            'extra_action_count' => $state->competitiveExtraActionCountFor($actor),
        ]);
    }

    private function affinityLog(BattleActor $attacker, BattleActor $defender): string
    {
        $multiplier = $this->affinityMultiplier($attacker, $defender);
        $label = BattleTypeAffinity::label($multiplier);

        if ($multiplier > 1.01) {
            $bonusPercent = (int) round(($multiplier - 1.0) * 100);
            return "<span class=\"text-emerald-700 font-bold\">【戦型相性】{$attacker->name} → {$defender->name}: {$label}！ 与ダメージ +{$bonusPercent}%</span>";
        }

        if ($multiplier < 0.99) {
            $penaltyPercent = (int) round((1.0 - $multiplier) * 100);
            return "<span class=\"text-rose-700 font-bold\">【戦型相性】{$attacker->name} → {$defender->name}: {$label}…… 与ダメージ -{$penaltyPercent}%</span>";
        }

        return "<span class=\"text-slate-500 font-bold\">【戦型相性】{$attacker->name} → {$defender->name}: 互角</span>";
    }
}
