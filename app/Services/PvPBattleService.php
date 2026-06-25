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

    protected CharacterStatusService $statusService;
    protected DamageCalculator $damageCalculator;

    public function __construct(CharacterStatusService $statusService, DamageCalculator $damageCalculator)
    {
        $this->statusService = $statusService;
        $this->damageCalculator = $damageCalculator;
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
            $attackerRanking = ArenaRanking::firstOrCreate(
                ['character_id' => $attackerChar->id],
                ['rank' => ArenaRanking::max('rank') + 1, 'wins' => 0, 'losses' => 0]
            );
            $defenderRanking = ArenaRanking::firstOrCreate(
                ['character_id' => $defenderChar->id],
                ['rank' => ArenaRanking::max('rank') + 1, 'wins' => 0, 'losses' => 0]
            );

            $attackerOldRank = $attackerRanking->rank;
            $defenderOldRank = $defenderRanking->rank;
            $attackerNewRank = $attackerOldRank;
            $defenderNewRank = $defenderOldRank;

            if ($isAttackerWin) {
                $attackerRanking->wins += 1;
                $defenderRanking->losses += 1;

                // 勝利した場合、自分の順位が1つ上がり（-1）、元々その順位にいた人が1つ下がる（+1）
                // 自分が1位の場合は上がらない
                if ($attackerOldRank > 1) {
                    $targetRank = $attackerOldRank - 1;
                    $targetRanking = ArenaRanking::where('rank', $targetRank)->lockForUpdate()->first();
                    
                    if ($targetRanking) {
                        $rankDownCharacter = $targetRanking->character;

                        // rank はユニーク制約があるため、一時ランクへ退避してから入れ替える。
                        $temporaryRank = -1 * (int) $attackerRanking->id;
                        $attackerRanking->rank = $temporaryRank;
                        $attackerRanking->save();

                        $targetRanking->rank = $attackerOldRank;
                        $targetRanking->save();

                        if ((int) $defenderRanking->id === (int) $targetRanking->id) {
                            $defenderRanking->rank = $attackerOldRank;
                        }

                        if ($rankDownCharacter && (int) $rankDownCharacter->id !== (int) $attackerChar->id) {
                            app(CharacterNotificationService::class)->create(
                                $rankDownCharacter,
                                'arena',
                                'arena_rank_down',
                                'ランク戦順位が低下しました',
                                "{$attackerChar->name}さんの勝利により、闘技場順位が{$targetRank}位から{$attackerOldRank}位に下がりました。",
                                '順位を見る',
                                route('colosseum.ranking'),
                                [
                                    'attacker_id' => (int) $attackerChar->id,
                                    'old_rank' => (int) $targetRank,
                                    'new_rank' => (int) $attackerOldRank,
                                ],
                                85
                            );
                        }

                        $attackerRanking->rank = $targetRank;
                    } else {
                        // 対象がいない場合（データ不整合など）は単純に上がる
                        $attackerRanking->rank = $targetRank;
                    }
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
        });

        return $result;
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
        if ($this->shouldUseRankSkill($attacker)) {
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
    }

    /**
     * スキル（必殺技）の実行
     */
    protected function executeSkillAction(BattleActor $attacker, BattleActor $defender, BattleState $state, Skill $skill): void
    {
        $state->addLog("<span class=\"text-blue-600 font-bold\">【必殺技】{$attacker->name} の必殺技、{$skill->name} が発動！</span>");
        
        $hitCount = max(1, $skill->hit_count);
        if ($skill->hit_count == 0 && in_array($skill->damage_type, ['heal', 'support'])) {
            $hitCount = 1; 
        }

        for ($i = 0; $i < $hitCount; $i++) {
            $damage = 0;
            $isCrit = false;
            $skillPowerInt = (int)($skill->power_multiplier * 100);

            $overrideDef = null;
            $overrideSpr = null;
            if ($skill->def_ignore_percent > 0) {
                $overrideDef = (int)($defender->def * (1 - ($skill->def_ignore_percent / 100)));
                $overrideSpr = (int)($defender->spr * (1 - ($skill->def_ignore_percent / 100)));
            }

            if (str_contains($skill->description, 'LUKに応じて')) {
                $lukBonus = (int)($attacker->luk * 0.5);
                $skillPowerInt += $lukBonus;
            }
            
            if (str_contains($skill->description, '確率で追加')) {
                if (rand(1, 100) <= 30) {
                    $hitCount++;
                }
            }

            if ($skill->power_multiplier > 0) {
                $affinityMultiplier = $this->affinityMultiplier($attacker, $defender);
                if (in_array($skill->damage_type, ['physical', 'gold', 'drop'], true)) {
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
                    $hybridAtk = (int)(($attacker->str + $attacker->mag) / 2);
                    if (str_contains($skill->description, '高い方依存')) {
                        $hybridAtk = max($attacker->str, $attacker->mag);
                    }
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
                $defender->takeDamage($damage);
                $this->rewardRankBattleFocusAfterDamage($attacker, $defender, $damage, $isCrit, $affinityMultiplier ?? 1.0);
                $state->addLog("{$defender->name} に <span class=\"text-red-600 font-extrabold text-lg\">{$damage}</span> のダメージ！");
            }
            
            if ($defender->isDead()) break;
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
        }

        if ($skill->enemy_def_down_percent > 0) {
            $effect = (int)($skill->enemy_def_down_percent);
            $state->addLog("{$defender->name} の防御力が {$effect}% 低下した！");
            $defender->def -= (int)($defender->baseDef * ($effect / 100));
            $defender->def = max(1, $defender->def);
        }
        if ($skill->enemy_spd_down_percent > 0) {
            $effect = (int)($skill->enemy_spd_down_percent);
            $state->addLog("{$defender->name} の素早さが {$effect}% 低下した！");
            $defender->agi -= (int)($defender->baseAgi * ($effect / 100));
            $defender->agi = max(1, $defender->agi);
        }
        if ($skill->enemy_spr_down_percent > 0) {
            $effect = (int)($skill->enemy_spr_down_percent);
            $state->addLog("{$defender->name} の精神力が {$effect}% 低下した！");
            $defender->spr -= (int)($defender->baseSpr * ($effect / 100));
            $defender->spr = max(1, $defender->spr);
        }

        if ($skill->damage_reduction_percent > 0) {
            $state->addLog("{$attacker->name} は次の被ダメージを軽減する構えをとった！");
            $attacker->damageReductionRate = $skill->damage_reduction_percent;
        }
        
        if ($skill->damage_type === 'support' && str_contains($skill->description, '上昇')) {
            $state->addLog("{$attacker->name} の攻撃力と魔法力が上昇した！");
            $attacker->str += (int)($attacker->baseStr * 0.05);
            $attacker->mag += (int)($attacker->baseMag * 0.05);
            $attacker->str = min($attacker->str, (int)($attacker->baseStr * 1.5));
            $attacker->mag = min($attacker->mag, (int)($attacker->baseMag * 1.5));
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
        if (in_array($type, ['physical', 'magical'], true)) {
            return $type;
        }

        return 'physical';
    }

    private function affinityMultiplier(BattleActor $attacker, BattleActor $defender): float
    {
        return BattleTypeAffinity::multiplier($attacker->battleTypeWeights, $defender->battleTypeWeights);
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
        $baseCost = $skill->spCostForMaxSp($attacker->maxMp);
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
