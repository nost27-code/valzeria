<?php

namespace App\Services;

use App\Models\Character;
use App\Models\ArenaRanking;
use App\Models\ArenaLog;
use App\Models\Skill;
use App\Services\CharacterNotificationService;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\BattleTypeAffinity;
use App\Services\Battle\DamageCalculator;
use App\Services\Battle\BattleResult;
use Illuminate\Support\Facades\DB;

class PvPBattleService
{
    private const PVP_HIT_AGI_FACTOR = 0.08;
    private const PVP_MIN_HIT_RATE = 84;
    private const PVP_MAX_HIT_RATE = 97;
    private const PVP_TURN_SPEED_RANDOM = 2;
    private const PVP_FOCUS_MAX = 100;
    private const PVP_FOCUS_ATTACK_GAIN = 25;
    private const PVP_FOCUS_DAMAGE_GAIN = 15;
    private const PVP_FOCUS_BONUS_GAIN = 10;
    private const PVP_FOCUS_EARLY_THRESHOLD = 80;
    private const PVP_FOCUS_EARLY_RATE = 20;
    private const PVP_NORMAL_POWER_MULTIPLIER = 125;
    private const PVP_SKILL_COST_MAX_SP_RATE = 0.25;
    private const PUBLIC_RANK_UP_LOG_MAX_RANK = 50;

    protected CharacterStatusService $statusService;
    protected DamageCalculator $damageCalculator;
    protected JobArtBattleSupportService $jobArtBattleSupport;

    public function __construct(
        CharacterStatusService $statusService,
        DamageCalculator $damageCalculator,
        JobArtBattleSupportService $jobArtBattleSupport
    )
    {
        $this->statusService = $statusService;
        $this->damageCalculator = $damageCalculator;
        $this->jobArtBattleSupport = $jobArtBattleSupport;
    }

    /**
     * PvPの自動戦闘を行い、結果を返すとともに順位・ログの更新を行う
     * 
     * @return BattleResult
     */
    public function executeBattle(Character $attackerChar, Character $defenderChar): BattleResult
    {
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
            : $attackerChar->currentJob()->with('skill')->first();
        if ($attackerJob?->skill) {
            $attackerActor->skill = $attackerJob->skill;
        }
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
            : $defenderChar->currentJob()->with('skill')->first();
        if ($defenderJob?->skill) {
            $defenderActor->skill = $defenderJob->skill;
        }
        $defenderActor->jobKey = $defenderJob?->key;
        $defenderActor->battleTypeWeights = BattleTypeAffinity::normalize($this->battleTypeWeights($defenderJob));
        $defenderActor->normalAttackType = $this->normalAttackType($defenderJob);
        $this->jobArtBattleSupport->attachBossSet($defenderActor, $defenderChar, 'champ');

        $state = new BattleState($attackerActor, $defenderActor);
        
        $state->addLog("【闘技場】{$attackerActor->name} が {$defenderActor->name} に勝負を挑んだ！");
        $state->addLog($this->affinityLog($attackerActor, $defenderActor));
        $state->addLog($this->affinityLog($defenderActor, $attackerActor));

        // ターンループ (最大20ターン程度で打ち切るなどが必要だが、既存と同じくisBattleEndedで判定)
        $maxTurns = 30;
        while (!$state->isBattleEnded() && $state->turnCount < $maxTurns) {
            $state->turnCount++;
            $state->addLog("<br><br>--- ターン {$state->turnCount} ---");
            
            $attackerSpeed = $attackerActor->agi + rand(0, self::PVP_TURN_SPEED_RANDOM);
            $defenderSpeed = $defenderActor->agi + rand(0, self::PVP_TURN_SPEED_RANDOM);
            
            if ($attackerSpeed >= $defenderSpeed) {
                $this->executeAction($attackerActor, $defenderActor, $state);
                if ($state->isBattleEnded()) break;
                $this->executeAction($defenderActor, $attackerActor, $state);
            } else {
                $this->executeAction($defenderActor, $attackerActor, $state);
                if ($state->isBattleEnded()) break;
                $this->executeAction($attackerActor, $defenderActor, $state);
            }
        }

        // 戦闘終了と順位変動処理
        $isAttackerWin = false;
        
        $isTurnLimit = $state->turnCount >= $maxTurns;
        if ($defenderActor->isDead()) {
            // アタッカー勝利
            $state->addLog("<br><span class=\"text-black font-extrabold text-xl\">決着！{$attackerActor->name}は、{$defenderActor->name}を倒した！</span>");
            $result->result = 'victory';
            $isAttackerWin = true;
        } elseif (!$attackerActor->isDead() && $isTurnLimit && $attackerActor->hp > $defenderActor->hp) {
            // ターン上限時は残HPが多い挑戦者の判定勝利
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

        // キャラクターのHP/SPは闘技場では減らさない仕様にするのが一般的だが、
        // 現状は引継ぎで設定（PvP後に回復するかは外で制御するかもしれないが、今回はHP更新しない方向でも良い。
        // とりあえず戦闘での消耗は残すならsaveするが、闘技場専用なら減らない方が親切。ここでは減らさず、外のColosseumScreenに委ねる）

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

        return $result;
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
    protected function executeAction(BattleActor $attacker, BattleActor $defender, BattleState $state): void
    {
        $attacker->isDefending = false;
        $attacker->damageReductionRate = 0;
        $this->addFocus($attacker, self::PVP_FOCUS_ATTACK_GAIN);

        $usedSkill = false;
        $this->jobArtBattleSupport->tickCooldowns($state, $attacker);
        $jobArt = $this->jobArtBattleSupport->selectForTurn($attacker, $state);
        if ($jobArt) {
            $this->jobArtBattleSupport->consumeAndMarkUse($attacker, $state, $jobArt);
            $state->addLog($this->jobArtBattleSupport->activationLog($attacker, $defender, $jobArt));
            $this->executeSkillAction(
                $attacker,
                $defender,
                $state,
                $this->jobArtBattleSupport->skillForExecution($attacker, $jobArt),
                false
            );
            $usedSkill = true;
        }

        if (!$usedSkill && $this->shouldUseRankSkill($attacker)) {
            $spCost = $this->rankBattleSkillSpCost($attacker, $attacker->skill);
            if ($attacker->mp >= $spCost) {
                $attacker->mp -= $spCost;
                $this->resetFocus($attacker);
                $state->addLog("<span class=\"text-indigo-600 font-bold\">【闘気解放】{$attacker->name} の闘気が満ちた！</span>");
                $this->executeSkillAction($attacker, $defender, $state, $attacker->skill);
                $usedSkill = true;
            } else {
                $state->addLog("<span class=\"text-slate-500 font-bold\">{$attacker->name} の闘気は高まったが、SPが足りない！</span>");
            }
        }

        if (!$usedSkill) {
            $this->executeNormalAttack($attacker, $defender, $state, self::PVP_NORMAL_POWER_MULTIPLIER);
        }
    }

    /**
     * 通常の物理攻撃処理
     */
    protected function executeNormalAttack(BattleActor $attacker, BattleActor $defender, BattleState $state, int $powerMultiplier = 100): void
    {
        if (!$this->damageCalculator->isHit(
            $attacker,
            $defender,
            100,
            self::PVP_HIT_AGI_FACTOR,
            self::PVP_MIN_HIT_RATE,
            self::PVP_MAX_HIT_RATE
        )) {
            $state->addLog("{$attacker->name} の攻撃！……しかし、{$defender->name} はかわした！");
            return;
        }

        $attackType = $attacker->usesMagForNormalAttack() ? 'magical' : 'physical';
        $isCrit = $this->damageCalculator->isRankBattleCritical($attacker, $defender);
        $affinityMultiplier = $this->affinityMultiplier($attacker, $defender);
        $damage = $this->damageCalculator->calculateRankBattleDamage(
            $attacker,
            $defender,
            $attackType,
            $powerMultiplier,
            $isCrit,
            $affinityMultiplier
        );
        $defender->takeDamage($damage);
        $this->rewardRankBattleFocusAfterDamage($attacker, $defender, $damage, $isCrit, $affinityMultiplier);

        $critText = $isCrit ? "<span class=\"text-orange-500 font-bold\">【痛恨の一撃！】</span>" : "";
        $damageClass = $attackType === 'magical' ? 'text-purple-600' : 'text-red-600';
        $state->addLog("{$attacker->name} の攻撃！ {$critText} {$defender->name} に <span class=\"{$damageClass} font-extrabold text-lg\">{$damage}</span> のダメージ！");
        $this->logGutsIfTriggered($defender, $state);
    }

    protected function executePhysicalAttack(BattleActor $attacker, BattleActor $defender, BattleState $state, int $powerMultiplier = 100): void
    {
        if (!$this->damageCalculator->isHit(
            $attacker,
            $defender,
            100,
            self::PVP_HIT_AGI_FACTOR,
            self::PVP_MIN_HIT_RATE,
            self::PVP_MAX_HIT_RATE
        )) {
            $state->addLog("{$attacker->name} の攻撃！……しかし、{$defender->name} はかわした！");
            return;
        }

        $isCrit = $this->damageCalculator->isRankBattleCritical($attacker, $defender);
        $affinityMultiplier = $this->affinityMultiplier($attacker, $defender);
        $damage = $this->damageCalculator->calculateRankBattleDamage(
            $attacker,
            $defender,
            'physical',
            $powerMultiplier,
            $isCrit,
            $affinityMultiplier
        );
        $defender->takeDamage($damage);
        $this->rewardRankBattleFocusAfterDamage($attacker, $defender, $damage, $isCrit, $affinityMultiplier);

        $critText = $isCrit ? "<span class=\"text-orange-500 font-bold\">【痛恨の一撃！】</span>" : "";
        $state->addLog("{$attacker->name} の攻撃！ {$critText} {$defender->name} に <span class=\"text-red-600 font-extrabold text-lg\">{$damage}</span> のダメージ！");
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
        bool $addOpeningLog = true
    ): void
    {
        if ($addOpeningLog) {
            $state->addLog("<span class=\"text-blue-600 font-bold\">【必殺技】{$attacker->name} の必殺技、{$skill->name} が発動！</span>");
        }
        
        $hitCount = max(1, (int) $skill->hit_count);
        if ((int) $skill->hit_count === 0 && in_array($skill->damage_type, ['heal', 'support'], true)) {
            $hitCount = 1; 
        }
        if ((int) $skill->extra_hit_chance_percent > 0 && random_int(1, 100) <= (int) $skill->extra_hit_chance_percent) {
            $hitCount++;
        }

        $totalDamage = 0;
        for ($i = 0; $i < $hitCount; $i++) {
            $damage = 0;
            $isCrit = false;
            $skillPowerInt = max(0, (int) round((float) $skill->power_multiplier * 100));

            $overrideDef = null;
            $overrideSpr = null;
            if ((int) $skill->def_ignore_percent > 0) {
                $overrideDef = (int) floor($defender->def * (1 - ((int) $skill->def_ignore_percent / 100)));
                $overrideSpr = (int) floor($defender->spr * (1 - ((int) $skill->def_ignore_percent / 100)));
            }

            if ((float) $skill->luk_power_rate > 0) {
                $skillPowerInt += (int) floor($attacker->luk * (float) $skill->luk_power_rate);
            }

            if ((float) $skill->power_multiplier > 0) {
                $affinityMultiplier = $this->affinityMultiplier($attacker, $defender);
                if (in_array($skill->damage_type, ['physical', 'gold', 'drop', 'support'], true)) {
                    $damage = $this->damageCalculator->calculateRankBattleDamage(
                        $attacker,
                        $defender,
                        'physical',
                        $skillPowerInt,
                        $isCrit,
                        $affinityMultiplier,
                        null,
                        $overrideDef,
                        $overrideSpr,
                        true,
                        $hitCount
                    );
                } elseif ($skill->damage_type === 'magical') {
                    $damage = $this->damageCalculator->calculateRankBattleDamage(
                        $attacker,
                        $defender,
                        'magical',
                        $skillPowerInt,
                        $isCrit,
                        $affinityMultiplier,
                        null,
                        $overrideDef,
                        $overrideSpr,
                        true,
                        $hitCount
                    );
                } elseif ($skill->damage_type === 'hybrid') {
                    $hybridAtk = (string) $skill->hybrid_scaling === 'max'
                        ? max($attacker->str, $attacker->mag)
                        : (int) floor(($attacker->str + $attacker->mag) / 2);
                    $damage = $this->damageCalculator->calculateRankBattleDamage(
                        $attacker,
                        $defender,
                        'physical',
                        $skillPowerInt,
                        $isCrit,
                        $affinityMultiplier,
                        $hybridAtk,
                        $overrideDef,
                        $overrideSpr,
                        true,
                        $hitCount
                    );
                }
            }

            if ($damage > 0) {
                $totalDamage += $damage;
                $defender->takeDamage($damage);
                $this->rewardRankBattleFocusAfterDamage($attacker, $defender, $damage, $isCrit, $affinityMultiplier ?? 1.0);
                $state->addLog("{$defender->name} に <span class=\"text-red-600 font-extrabold text-lg\">{$damage}</span> のダメージ！");
                $this->logGutsIfTriggered($defender, $state);
            }

            if ($defender->isDead()) break;
        }

        if ($skill->isJobArt()) {
            $this->applyJobArtTemplateEffects($attacker, $defender, $state, $skill, $totalDamage);
        }

        if ($skill->heal_percent > 0) {
            $healAmount = (int)($attacker->maxHp * ($skill->heal_percent / 100));
            $attacker->healHp($healAmount);
            $state->addLog("<span class=\"text-green-600 font-bold\">{$attacker->name} の傷が {$healAmount} 回復した！</span>");
        }

        if ($skill->mp_recover_percent > 0 && $attacker->maxMp > 0) {
            $mpHealAmount = (int)($attacker->maxMp * ($skill->mp_recover_percent / 100));
            $attacker->mp = min($attacker->maxMp, $attacker->mp + $mpHealAmount);
            $state->addLog("<span class=\"text-blue-500 font-bold\">{$attacker->name} はSPを {$mpHealAmount} 回復した！</span>");
        }

        if ($skill->self_damage_percent > 0) {
            $selfDamage = (int)($attacker->maxHp * ($skill->self_damage_percent / 100));
            $attacker->takeDamage($selfDamage);
            $state->addLog("<span class=\"text-purple-600 font-bold\">反動により、{$attacker->name} は {$selfDamage} のダメージを受けた！</span>");
            $this->logGutsIfTriggered($attacker, $state);
        }

        $this->applyStructuredDebuffs($defender, $state, $skill);

        if ((int) $skill->damage_reduction_percent > 0 && ! ($skill->isJobArt() && in_array((string) $skill->effect_template, ['GUARD_BARRIER', 'DAMAGE_GUARD_BARRIER'], true))) {
            $state->addLog("{$attacker->name} は次の被ダメージを軽減する構えをとった！");
            $attacker->damageReductionRate = max($attacker->damageReductionRate, min(25, (int) $skill->damage_reduction_percent));
        }
        
        if (!$skill->isJobArt() && (int) $skill->self_buff_percent > 0) {
            $state->addLog("{$attacker->name} の攻撃力と魔法力が上昇した！");
            $rate = (int) $skill->self_buff_percent / 100;
            $attacker->str = min((int) floor($attacker->baseStr * 1.5), $attacker->str + (int) floor($attacker->baseStr * $rate));
            $attacker->mag = min((int) floor($attacker->baseMag * 1.5), $attacker->mag + (int) floor($attacker->baseMag * $rate));
        }
    }

    private function applyJobArtTemplateEffects(
        BattleActor $attacker,
        BattleActor $defender,
        BattleState $state,
        Skill $skill,
        int $totalDamage
    ): void {
        $template = (string) $skill->effect_template;
        $power = max(1, (int) ($skill->power ?: 100));

        if (in_array($template, ['HEAL', 'HEAL_CLEANSE'], true)) {
            $heal = max(1, (int) floor($attacker->spr * ($power / 100)));
            $attacker->healHp($heal);
            $state->addLog("<span class=\"text-emerald-600 font-bold\">HPが {$heal} 回復した！</span>");
        }

        if ($template === 'DRAIN' && $totalDamage > 0 && (float) $skill->drain_hp_rate > 0) {
            $heal = max(1, (int) floor($totalDamage * (float) $skill->drain_hp_rate));
            $attacker->healHp($heal);
            $state->addLog("<span class=\"text-emerald-600 font-bold\">与えた力を吸収し、HPが {$heal} 回復した！</span>");
        }

        if ($template === 'GUTS') {
            $attacker->gutsReady = true;
            $state->addLog("<span class=\"text-orange-700 font-bold\">{$attacker->name} は一度だけ踏みとどまる覚悟を固めた！</span>");
        }


        if (in_array($template, ['GUARD_BARRIER', 'DAMAGE_GUARD_BARRIER'], true)) {
            $rate = (float) ($attacker->jobArtRates[(int) $skill->id] ?? 1.0);
            $reduction = $this->jobArtGuardReduction($skill, $rate);
            $attacker->damageReductionRate = max($attacker->damageReductionRate, min(25, $reduction));
            $state->addLog("<span class=\"text-blue-700 font-bold\">{$attacker->name} は次の被ダメージを {$reduction}% 軽減する！</span>");
        }

        if (in_array($template, ['SELF_BUFF', 'DAMAGE_BUFF', 'MAGICAL_DAMAGE_BUFF'], true)) {
            $beforeStr = $attacker->str;
            $beforeMag = $attacker->mag;
            $attacker->str = min((int) floor($attacker->baseStr * 1.5), $attacker->str + max(1, (int) floor($attacker->baseStr * 0.10)));
            $attacker->mag = min((int) floor($attacker->baseMag * 1.5), $attacker->mag + max(1, (int) floor($attacker->baseMag * 0.10)));
            $this->logStatChange($state, $attacker->name, 'ATK', $beforeStr, $attacker->str, 'MAG', $beforeMag, $attacker->mag, true);
        }

        if (in_array($template, ['ENEMY_DEBUFF', 'DAMAGE_DEBUFF'], true) && !$this->hasStructuredDebuff($skill)) {
            $beforeDef = $defender->def;
            $beforeSpr = $defender->spr;
            $defender->def = max(1, $defender->def - max(1, (int) floor($defender->baseDef * 0.10)));
            $defender->spr = max(1, $defender->spr - max(1, (int) floor($defender->baseSpr * 0.05)));
            $this->logStatChange($state, $defender->name, 'DEF', $beforeDef, $defender->def, 'SPR', $beforeSpr, $defender->spr, false);
        }

        if ($template === 'TIME_CONTROL_CURRENT_ONLY' && !$this->hasStructuredDebuff($skill)) {
            $rate = (int) $skill->enemy_spd_down_percent > 0 ? (int) $skill->enemy_spd_down_percent / 100 : 0.10;
            $before = $defender->agi;
            $defender->agi = max(1, $defender->agi - max(1, (int) floor($defender->baseAgi * $rate)));
            $pct = $before > 0 ? (int) round((abs($before - $defender->agi) / $before) * 100) : 0;
            $state->addLog("<span class=\"text-sky-700 font-bold\">{$defender->name} のSPDが {$pct}% 低下した！</span>");
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
        $mainPct = $mainBefore > 0 ? (int) round((abs($mainAfter - $mainBefore) / $mainBefore) * 100) : 0;
        $subPct = $subBefore > 0 ? (int) round((abs($subAfter - $subBefore) / $subBefore) * 100) : 0;

        if ($mainAfter === $mainBefore && $subAfter === $subBefore) {
            $color = $isBuff ? 'text-indigo-600' : 'text-violet-700';
            $verb = $isBuff ? '強化' : '弱体化';
            $state->addLog("<span class=\"{$color} font-bold\">{$actorName} はこれ以上{$verb}できない！</span>");
            return;
        }

        $color = $isBuff ? 'text-indigo-600' : 'text-violet-700';
        $direction = $isBuff ? '上昇' : '低下';
        $state->addLog("<span class=\"{$color} font-bold\">{$actorName} の{$mainLabel}が {$mainPct}% / {$subLabel}が {$subPct}% {$direction}した！</span>");
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
            return min(25, max(1, (int) floor((int) $skill->damage_reduction_percent * $rate)));
        }

        // powerは呼び出し元でskillForExecution()により既に継承倍率でスケール済み
        return min(25, max(10, (int) floor(max(80, (int) ($skill->power ?: 100)) / 10)));
    }

    private function applyStructuredDebuffs(BattleActor $defender, BattleState $state, Skill $skill): void
    {
        $debuffs = [
            'enemy_atk_down_percent' => ['prop' => 'str', 'base' => 'baseStr', 'label' => '攻撃力'],
            'enemy_mag_down_percent' => ['prop' => 'mag', 'base' => 'baseMag', 'label' => '魔法力'],
            'enemy_def_down_percent' => ['prop' => 'def', 'base' => 'baseDef', 'label' => '防御力'],
            'enemy_spr_down_percent' => ['prop' => 'spr', 'base' => 'baseSpr', 'label' => '精神力'],
            'enemy_spd_down_percent' => ['prop' => 'agi', 'base' => 'baseAgi', 'label' => '素早さ'],
        ];

        foreach ($debuffs as $field => $config) {
            $effect = (int) ($skill->{$field} ?? 0);
            if ($effect <= 0) {
                continue;
            }

            $prop = $config['prop'];
            $base = $config['base'];
            $defender->{$prop} = max(1, $defender->{$prop} - (int) floor($defender->{$base} * ($effect / 100)));
            $state->addLog("{$defender->name} の{$config['label']}が {$effect}% 低下した！");
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

    private function focus(BattleActor $actor): int
    {
        return (int) ($actor->conditions['rank_battle_focus'] ?? 0);
    }

    private function addFocus(BattleActor $actor, int $amount): void
    {
        $actor->conditions['rank_battle_focus'] = min(
            self::PVP_FOCUS_MAX,
            max(0, $this->focus($actor) + $amount)
        );
    }

    private function resetFocus(BattleActor $actor): void
    {
        $actor->conditions['rank_battle_focus'] = 0;
    }

    private function shouldUseRankSkill(BattleActor $attacker): bool
    {
        if (!$attacker->skill) {
            return false;
        }

        $focus = $this->focus($attacker);
        if ($focus >= self::PVP_FOCUS_MAX) {
            return true;
        }

        return $focus >= self::PVP_FOCUS_EARLY_THRESHOLD
            && rand(1, 100) <= self::PVP_FOCUS_EARLY_RATE;
    }

    private function rankBattleSkillSpCost(BattleActor $attacker, Skill $skill): int
    {
        $baseCost = $skill->specialSkillSpCostForMaxSp($attacker->maxMp);
        if ($baseCost <= 0 || $attacker->maxMp <= 0) {
            return $baseCost;
        }

        $rankBattleCap = max(1, (int) ceil($attacker->maxMp * self::PVP_SKILL_COST_MAX_SP_RATE));

        return min($baseCost, $rankBattleCap);
    }

    private function rewardRankBattleFocusAfterDamage(
        BattleActor $attacker,
        BattleActor $defender,
        int $damage,
        bool $isCritical,
        float $affinityMultiplier
    ): void {
        if ($damage <= 0) {
            return;
        }

        $this->addFocus($defender, self::PVP_FOCUS_DAMAGE_GAIN);

        if ($isCritical || $affinityMultiplier > 1.01) {
            $this->addFocus($attacker, self::PVP_FOCUS_BONUS_GAIN);
        }
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
