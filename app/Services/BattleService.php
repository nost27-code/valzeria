<?php

namespace App\Services;

use App\Models\Character;
use App\Models\Enemy;
use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageCalculator;
use App\Services\Battle\BattleResult;

class BattleService
{
    protected CharacterStatusService $statusService;
    protected DamageCalculator $damageCalculator;
    protected JobArtService $jobArtService;

    public function __construct(CharacterStatusService $statusService, DamageCalculator $damageCalculator, JobArtService $jobArtService)
    {
        $this->statusService = $statusService;
        $this->damageCalculator = $damageCalculator;
        $this->jobArtService = $jobArtService;
    }

    /**
     * 自動戦闘を行い、勝敗と戦闘ログを返す
     * 
     * @return BattleResult
     */
    public function executeBattle(Character $character, Enemy $enemy): BattleResult
    {
        $result = new BattleResult();
        app(EquipmentAutoUnequipService::class)->unequipInvalidItems($character);
        $character->refresh();

        // プレイヤーアクターの生成
        $stats = $this->statusService->getFinalStats($character);
        $playerActor = new BattleActor($character->name, true, [
            'hp' => $character->current_hp,
            'max_hp' => $stats['max_hp'],
            'mp' => $character->current_mp ?? 0,
            'max_mp' => $stats['max_mp'] ?? 0,
            'str' => $stats['str'],
            'def' => $stats['def'],
            'agi' => $stats['agi'],
            'mag' => $stats['mag'],
            'spr' => $stats['spr'],
            'luk' => $stats['luk'],
        ], clone $character);

        // プレイヤーの職業技をセット
        $currentJob = $character->relationLoaded('currentJob')
            ? $character->currentJob
            : $character->currentJob()->with('skill')->first();
        if ($currentJob?->skill) {
            $playerActor->skill = $currentJob->skill;
        }
        $playerActor->jobKey = $currentJob?->key;
        $playerActor->jobArtActivationPolicy = (string) ($character->job_art_activation_policy ?: 'normal');

        // 敵アクターの生成
        $enemyStats = $this->enemyBattleStats($character, $enemy);
        $enemyActor = new BattleActor($enemy->name, false, [
            'hp' => $enemyStats['max_hp'],
            'max_hp' => $enemyStats['max_hp'],
            'mp' => $enemy->max_mp ?? 0,
            'max_mp' => $enemy->max_mp ?? 0,
            'str' => $enemyStats['str'],
            'def' => $enemyStats['def'],
            'agi' => $enemyStats['agi'],
            'mag' => $enemyStats['mag'],
            'spr' => $enemyStats['spr'],
            'luk' => $enemy->luk ?? 10,
        ], clone $enemy);

        $battleContext = $enemy->is_boss ? 'boss' : 'pve';
        $jobArts = $this->jobArtService->battleArtsFor($character, $battleContext);
        $playerActor->jobArts = $jobArts->all();
        foreach ($jobArts as $art) {
            $playerActor->jobArtRates[(int) $art->id] = (float) $art->getAttribute('job_art_rate');
            $playerActor->jobArtOrigins[(int) $art->id] = (string) $art->getAttribute('job_art_origin');
        }

        $result->enemyStatDisplay = [
            'danger_rate' => $enemyStats['danger_rate'],
            'danger_label' => $enemyStats['danger_label'],
            'str' => [
                'base' => $enemyStats['base_str'],
                'bonus' => $enemyStats['bonus_str'],
                'total' => $enemyStats['str'],
            ],
            'def' => [
                'base' => $enemyStats['base_def'],
                'bonus' => $enemyStats['bonus_def'],
                'total' => $enemyStats['def'],
            ],
            'hp' => [
                'base' => $enemyStats['base_hp'],
                'bonus' => $enemyStats['bonus_hp'],
                'total' => $enemyStats['max_hp'],
            ],
        ];

        $state = new BattleState($playerActor, $enemyActor, $battleContext);
        
        $state->addLog("【戦闘開始】{$playerActor->name} は {$enemyActor->name} と遭遇した！");

        // ターンループ
        while (!$state->isBattleEnded()) {
            $state->turnCount++;
            $state->addLog("<br><br>--- ターン {$state->turnCount} ---");
            
            // 先攻後攻判定（AGI比較＋乱数）
            $playerSpeed = $playerActor->agi + rand(0, 5);
            $enemySpeed = $enemyActor->agi + rand(0, 5);
            
            if ($playerSpeed >= $enemySpeed) {
                $this->executeAction($playerActor, $enemyActor, $state);
                if ($state->isBattleEnded()) break;
                $this->executeAction($enemyActor, $playerActor, $state);
            } else {
                $this->executeAction($enemyActor, $playerActor, $state);
                if ($state->isBattleEnded()) break;
                $this->executeAction($playerActor, $enemyActor, $state);
            }
        }

        // 戦闘終了処理
        if ($playerActor->isDead()) {
            $state->addLog("<br><span class=\"text-black font-extrabold text-xl\">{$playerActor->name}は、倒れてしまった……。</span>");
            $result->result = 'defeat';
            
            // 敗北時のペナルティとして、HPを最大値の30%、SPを10%にする
            $playerActor->hp = max(1, (int)($playerActor->maxHp * 0.3));
            $playerActor->mp = (int)($playerActor->maxMp * 0.1);
        } else if ($enemyActor->isDead()) {
            $state->addLog("<br><span class=\"text-black font-extrabold text-xl\">{$playerActor->name}は、{$enemyActor->name}を倒した！</span>");
            $result->result = 'victory';
            
            // 報酬の付与
            $exp = $enemy->exp_reward;
            $gold = $this->rollGoldReward($enemy, $state->goldBonusPercent);
            
            $result->exp = $exp;
            $result->gold = $gold;
            if ($gold > 0) {
                $state->addLog("<br><span class=\"text-amber-700 font-bold\">【Gold獲得】{$enemyActor->name} が持っていた <span class=\"text-amber-600 font-extrabold\">{$gold}G</span> を手に入れた！</span>");
            }
            
            // 職業経験値（J-EXP）の算出ロジック
            if ($enemy->job_exp_reward > 0) {
                $result->jobExp = $enemy->job_exp_reward;
            } else {
                $levelDiff = $enemy->level - $character->level;
                $jobExp = 0;

                if ($levelDiff <= -3) {
                    $jobExp = 0;
                } elseif ($levelDiff <= -1) {
                    $jobExp = rand(0, 1);
                } elseif ($levelDiff <= 2) {
                    $jobExp = rand(1, 2);
                } else {
                    $jobExp = rand(1, 3);
                }

                if ($enemy->is_boss) {
                    $jobExp += rand(1, 2);
                }

                $result->jobExp = $jobExp;
            }
        } else {
            $state->addLog("<br><span class=\"text-black font-extrabold text-xl\">双方が疲弊し、戦闘は終了した。</span>");
            $result->result = 'timeout';
        }

        $result->logs = $state->logs;
        $result->playerHpAfter = $playerActor->hp;
        $result->playerMpAfter = $playerActor->mp;
        $result->dropBonusPercent = $state->dropBonusPercent;
        $result->rareBonusPercent = $state->rareBonusPercent;

        // キャラクターのHP/SPを更新
        $character->current_hp = $playerActor->hp;
        $character->current_mp = $playerActor->mp;
        $character->save();

        return $result;
    }

    private function enemyBattleStats(Character $character, Enemy $enemy): array
    {
        $str = (int) $enemy->str;
        $def = (int) $enemy->def;
        $agi = (int) $enemy->agi;
        $mag = (int) $enemy->mag;
        $spr = (int) ($enemy->spr ?? $enemy->def);
        $hp = max(1, (int) $enemy->max_hp);
        $area = $enemy->relationLoaded('area') ? $enemy->area : $enemy->area()->first();
        $cityId = (int) ($area?->city_id ?? 0);

        if ($cityId >= 1 && $cityId <= 3) {
            $str = max(1, (int) floor($str * 0.92));
            $mag = max(1, (int) floor($mag * 0.92));
        }

        $danger = $this->enemyDangerBonus($character, $enemy);

        return [
            'base_hp' => $hp,
            'base_str' => $str,
            'base_def' => $def,
            'max_hp' => $hp + $danger['hp'],
            'str' => $str + $danger['str'],
            'def' => $def + $danger['def'],
            'agi' => $agi + $danger['agi'],
            'mag' => $mag + $danger['mag'],
            'spr' => $spr + $danger['spr'],
            'bonus_hp' => $danger['hp'],
            'bonus_str' => $danger['str'],
            'bonus_def' => $danger['def'],
            'danger_rate' => $danger['rate'],
            'danger_label' => $danger['label'],
        ];
    }

    private function rollGoldReward(Enemy $enemy, int $goldBonusPercent = 0): int
    {
        $rate = (float) config($enemy->is_boss ? 'gold.battle.boss_drop_rate' : 'gold.battle.normal_drop_rate', $enemy->is_boss ? 12 : 5);
        if (random_int(1, 10000) > (int) round($rate * 100)) {
            return 0;
        }

        $base = max(5, (int) ($enemy->gold_reward ?? 0));
        $variance = random_int(80, 120) / 100;
        $amount = (int) floor($base * $variance);

        if ($goldBonusPercent > 0) {
            $amount = (int) floor($amount * (100 + $goldBonusPercent) / 100);
        }

        return max(1, $amount);
    }

    private function enemyDangerBonus(Character $character, Enemy $enemy): array
    {
        if ($enemy->getAttribute('skip_danger_bonus')) {
            return $this->emptyDangerBonus();
        }

        if ($enemy->is_boss) {
            return $this->emptyDangerBonus();
        }

        $stateService = app(ExplorationStateService::class);
        $state = $stateService->currentFor($character);
        if (!$state || (int) $state->area_id !== (int) $enemy->area_id) {
            return $this->emptyDangerBonus();
        }

        $dangerRate = max(0, (int) ($state->danger_rate ?? 0));
        if ($dangerRate <= 0) {
            return $this->emptyDangerBonus($stateService->dangerLabel($dangerRate));
        }

        $depthService = app(ExplorationDepthService::class);
        $area = $enemy->relationLoaded('area') ? $enemy->area : $enemy->area()->first();
        $depthTier = $area
            ? $depthService->activeTierFor($character, $area, (int) ($state->exploration_point ?? 0), $dangerRate)
            : $depthService->tierFor((int) ($state->exploration_point ?? 0), $dangerRate);
        $enemyLevel = max(1, (int) ($enemy->level ?? 1));
        $targetLevel = $area
            ? max($enemyLevel, $depthService->targetLevelForTier($area, $depthTier))
            : $enemyLevel;
        $levelScale = max(1.0, $targetLevel / $enemyLevel);

        // 危険度と探索深度で段階的に強化する。
        // 低Lv敵を深度帯へ引き上げる際、レベル比をそのまま累乗するとスライム等が極端に跳ねるため、
        // 平方根ベースで「少し強い同系統の敵」に留める。
        $rate = min(8.0, ($dangerRate * 0.0025) + $depthService->enemyPowerBonusForTier($depthTier));
        $levelStatMultiplier = 1.0 + ((sqrt($levelScale) - 1.0) * 0.75);
        $statMultiplier = max(1.0 + $rate, $levelStatMultiplier);
        $hpMultiplier = max(
            $depthService->enemyHpMultiplierForTier($depthTier),
            sqrt($levelScale)
        );

        return [
            'rate' => $dangerRate,
            'label' => $stateService->dangerLabel($dangerRate),
            'hp' => max(0, (int) floor((int) $enemy->max_hp * ($hpMultiplier - 1.0))),
            'str' => max(0, (int) floor((int) $enemy->str * ($statMultiplier - 1.0))),
            'def' => max(0, (int) floor((int) $enemy->def * ($statMultiplier - 1.0))),
            'agi' => max(0, (int) floor((int) $enemy->agi * ($statMultiplier - 1.0))),
            'mag' => max(0, (int) floor((int) $enemy->mag * ($statMultiplier - 1.0))),
            'spr' => max(0, (int) floor((int) ($enemy->spr ?? $enemy->def) * ($statMultiplier - 1.0))),
        ];
    }

    private function emptyDangerBonus(string $label = '安定'): array
    {
        return [
            'rate' => 0,
            'label' => $label,
            'hp' => 0,
            'str' => 0,
            'def' => 0,
            'agi' => 0,
            'mag' => 0,
            'spr' => 0,
        ];
    }

    /**
     * 行動（通常攻撃またはスキル攻撃）
     */
    protected function executeAction(BattleActor $attacker, BattleActor $defender, BattleState $state): void
    {
        // 自分の手番が来たので、前ターンの防御状態・軽減状態を解除する
        $attacker->isDefending = false;
        $attacker->damageReductionRate = 0;

        // プレイヤーの行動（既存ロジック）
        if ($attacker->isPlayer) {
            // スキル発動判定 (通常攻撃前)
            $usedSkill = false;
            $this->tickJobArtCooldowns($state);
            $jobArt = $this->selectJobArtForTurn($attacker, $state);
            if ($jobArt) {
                $spCost = $this->jobArtSpCost($attacker, $jobArt);
                $attacker->mp -= $spCost;
                $this->executeJobArtAction($attacker, $defender, $state, $jobArt);
                $usedSkill = true;
            } elseif ($attacker->skill && rand(1, 100) <= $attacker->skill->effectiveActivationRate()) {
                $spCost = $attacker->skill->spCostForMaxSp($attacker->maxMp);
                if ($attacker->mp >= $spCost) {
                    $attacker->mp -= $spCost;
                    $this->executeSkillAction($attacker, $defender, $state, $attacker->skill);
                    $usedSkill = true;
                }
            }

            if (!$usedSkill) {
                // 通常攻撃
                $this->executeNormalAttack($attacker, $defender, $state);
            }
        } 
        // 敵の行動（AIロジック）
        else {
            $this->executeEnemyAction($attacker, $defender, $state);
        }
    }

    private function tickJobArtCooldowns(BattleState $state): void
    {
        foreach ($state->jobArtCooldowns as $skillId => $remaining) {
            $remaining = max(0, (int) $remaining - 1);
            if ($remaining <= 0) {
                unset($state->jobArtCooldowns[$skillId]);
            } else {
                $state->jobArtCooldowns[$skillId] = $remaining;
            }
        }
    }

    private function selectJobArtForTurn(BattleActor $attacker, BattleState $state): ?Skill
    {
        foreach ($attacker->jobArts as $art) {
            if (!$art instanceof Skill) {
                continue;
            }
            $skillId = (int) $art->id;
            if (($state->jobArtCooldowns[$skillId] ?? 0) > 0) {
                continue;
            }
            if ($art->max_uses_per_battle !== null && ($state->jobArtUseCounts[$skillId] ?? 0) >= (int) $art->max_uses_per_battle) {
                continue;
            }
            $spCost = $this->jobArtSpCost($attacker, $art);
            if (!$this->canActivateByPolicy($attacker, $state->battleType, $spCost)) {
                continue;
            }
            if (!$this->canActivateHealArt($attacker, $art)) {
                continue;
            }
            if (rand(1, 100) <= $art->effectiveActivationRate()) {
                return $art;
            }
        }

        return null;
    }

    private function jobArtSpCost(BattleActor $attacker, Skill $skill): int
    {
        $origin = (string) ($attacker->jobArtOrigins[(int) $skill->id] ?? 'current');

        return $skill->jobArtSpCostForMaxSp($attacker->maxMp, $origin);
    }

    private function canActivateByPolicy(BattleActor $actor, string $battleType, int $spCost): bool
    {
        if ($actor->mp < $spCost) {
            return false;
        }

        $spRate = $actor->maxMp > 0
            ? $actor->mp / $actor->maxMp
            : 0.0;

        return match ($actor->jobArtActivationPolicy) {
            'aggressive' => true,
            'normal' => $spRate >= 0.30,
            'conserve' => $spRate >= 0.60,
            'boss_only' => in_array($battleType, ['boss', 'champ'], true),
            default => $spRate >= 0.30,
        };
    }

    private function canActivateHealArt(BattleActor $actor, Skill $skill): bool
    {
        if (!$skill->isHealArt()) {
            return true;
        }

        if ($actor->maxHp <= 0) {
            return false;
        }

        return ($actor->hp / $actor->maxHp) <= 0.70;
    }

    /**
     * 通常の物理攻撃処理（共通化）
     */
    protected function executeNormalAttack(BattleActor $attacker, BattleActor $defender, BattleState $state, int $powerMultiplier = 100): void
    {
        if (!$this->damageCalculator->isHit($attacker, $defender)) {
            $state->addLog("{$attacker->name} の攻撃！……しかし、{$defender->name} はかわした！");
            return;
        }

        $isCrit = $this->damageCalculator->isCritical($attacker, $defender);
        $damage = $attacker->usesMagForNormalAttack()
            ? $this->damageCalculator->calculateMagicalDamage($attacker, $defender, $powerMultiplier, $isCrit)
            : $this->damageCalculator->calculatePhysicalDamage($attacker, $defender, $powerMultiplier, $isCrit);
        $defender->takeDamage($damage);

        $critText = $isCrit ? "<span class=\"text-orange-500 font-bold\">【痛恨の一撃！】</span>" : "";
        $damageClass = $attacker->usesMagForNormalAttack() ? 'text-purple-600' : 'text-red-600';
        $state->addLog("{$attacker->name} の攻撃！ {$critText} {$defender->name} に <span class=\"{$damageClass} font-extrabold text-lg\">{$damage}</span> のダメージ！");
    }

    protected function executePhysicalAttack(BattleActor $attacker, BattleActor $defender, BattleState $state, int $powerMultiplier = 100): void
    {
        if (!$this->damageCalculator->isHit($attacker, $defender)) {
            $state->addLog("{$attacker->name} の攻撃！……しかし、{$defender->name} はかわした！");
            return;
        }

        $isCrit = $this->damageCalculator->isCritical($attacker, $defender);
        $damage = $this->damageCalculator->calculatePhysicalDamage($attacker, $defender, $powerMultiplier, $isCrit);
        $defender->takeDamage($damage);
        
        $critText = $isCrit ? "<span class=\"text-orange-500 font-bold\">【痛恨の一撃！】</span>" : "";
        $state->addLog("{$attacker->name} の攻撃！ {$critText} {$defender->name} に <span class=\"text-red-600 font-extrabold text-lg\">{$damage}</span> のダメージ！");
    }

    /**
     * 敵キャラクター専用の行動ロジック（型に基づくAI）
     */
    protected function executeEnemyAction(BattleActor $attacker, BattleActor $defender, BattleState $state): void
    {
        $enemyModel = $attacker->originalModel;
        $typeName = $enemyModel->type_name ?? '標準型';
        $isBoss = $enemyModel->is_boss ?? false;

        $rand = rand(1, 100);

        // 1. ボスの特別行動 (15%で大技)
        if ($isBoss && $rand <= 15) {
            if ($typeName === '魔法型') {
                $state->addLog("<span class=\"text-purple-600 font-extrabold\">【大技】{$attacker->name} が魔力を解き放つ！！</span>");
                $this->executeMagicalAttack($attacker, $defender, $state, 180);
            } else {
                $state->addLog("<span class=\"text-purple-600 font-extrabold\">【大技】{$attacker->name} の強烈な一撃が炸裂する！！</span>");
                $this->executePhysicalAttack($attacker, $defender, $state, 180);
            }
            return;
        }

        // 2. 型に応じた行動分岐
        // 乱数の再取得（ボス行動判定とは別枠で計算）
        $rand = rand(1, 100);

        switch ($typeName) {
            case '魔法型':
                if ($rand <= 70) {
                    $this->executeMagicalAttack($attacker, $defender, $state);
                } else {
                    $this->executePhysicalAttack($attacker, $defender, $state);
                }
                break;

            case '耐久型':
            case '重装型':
                if ($rand <= 25) {
                    $state->addLog("<span class=\"text-blue-600 font-bold\">{$attacker->name} は防御の構えをとった！</span>");
                    $attacker->isDefending = true;
                } else {
                    $this->executePhysicalAttack($attacker, $defender, $state);
                }
                break;

            case '高速型':
                if ($rand <= 20) {
                    $state->addLog("<span class=\"text-blue-600 font-bold\">{$attacker->name} の連続攻撃！</span>");
                    $this->executePhysicalAttack($attacker, $defender, $state, 80);
                    if (!$defender->isDead()) {
                        $this->executePhysicalAttack($attacker, $defender, $state, 80);
                    }
                } else {
                    $this->executePhysicalAttack($attacker, $defender, $state);
                }
                break;

            case 'アンデッド型':
                if ($rand <= 30) {
                    $state->addLog("<span class=\"text-purple-600 font-bold\">{$attacker->name} は生命力を吸収しようと襲いかかった！</span>");
                    $beforeHp = $defender->hp;
                    $this->executePhysicalAttack($attacker, $defender, $state, 100);
                    $damageDealt = $beforeHp - $defender->hp;
                    if ($damageDealt > 0) {
                        $healAmount = (int)($damageDealt * 0.5);
                        $attacker->healHp($healAmount);
                        $state->addLog("<span class=\"text-green-600 font-bold\">{$attacker->name} はHPを {$healAmount} 回復した！</span>");
                    }
                } else {
                    $this->executePhysicalAttack($attacker, $defender, $state);
                }
                break;

            case '竜型':
                if ($rand <= 30) {
                    $state->addLog("<span class=\"text-red-600 font-extrabold\">{$attacker->name} のドラゴンブレス！！</span>");
                    // 魔法と物理の複合ダメージのような扱い(高倍率魔法)
                    $this->executeMagicalAttack($attacker, $defender, $state, 150);
                } else {
                    $this->executePhysicalAttack($attacker, $defender, $state, 120); // 竜は通常攻撃も少し重い
                }
                break;

            case '物理型':
            case '標準型':
            default:
                if ($rand <= 20) {
                    $state->addLog("<span class=\"text-orange-600 font-bold\">{$attacker->name} は渾身の力を込めて殴りかかった！</span>");
                    $this->executePhysicalAttack($attacker, $defender, $state, 150);
                } else {
                    $this->executePhysicalAttack($attacker, $defender, $state);
                }
                break;
        }
    }

    /**
     * 魔法攻撃処理
     */
    protected function executeMagicalAttack(BattleActor $attacker, BattleActor $defender, BattleState $state, int $powerMultiplier = 100): void
    {
        // 魔法も回避される可能性がある前提（命中判定）
        if (!$this->damageCalculator->isHit($attacker, $defender)) {
            $state->addLog("{$attacker->name} は魔法を唱えた！……しかし、{$defender->name} は抵抗した！");
            return;
        }

        $damage = $this->damageCalculator->calculateMagicalDamage($attacker, $defender, $powerMultiplier, false);
        $defender->takeDamage($damage);
        $state->addLog("{$attacker->name} の魔法攻撃！ {$defender->name} に <span class=\"text-purple-600 font-extrabold text-lg\">{$damage}</span> のダメージ！");
    }

    /**
     * スキル（必殺技）の実行
     */
    protected function executeSkillAction(BattleActor $attacker, BattleActor $defender, BattleState $state, \App\Models\Skill $skill): void
    {
        $state->addLog("<span class=\"text-blue-600 font-bold\">【必殺技】{$attacker->name} の必殺技、{$skill->name} が発動！</span>");
        
        $hitCount = max(1, $skill->hit_count);
        // 回復やサポート特化で攻撃しない場合
        if ($skill->hit_count == 0 && in_array($skill->damage_type, ['heal', 'support'])) {
            $hitCount = 1; 
        }

        for ($i = 0; $i < $hitCount; $i++) {
            $damage = 0;
            $isCrit = false; // 必殺技では会心を出さない
            $skillPowerInt = (int)($skill->power_multiplier * 100);

            // 敵DEF無視効果
            $overrideDef = null;
            $overrideSpr = null;
            if ($skill->def_ignore_percent > 0) {
                $overrideDef = (int)($defender->def * (1 - ($skill->def_ignore_percent / 100)));
                $overrideSpr = (int)($defender->spr * (1 - ($skill->def_ignore_percent / 100)));
            }

            // LUK依存（侍、剣聖など）の特別対応: 説明文から判定して加算
            if (str_contains($skill->description, 'LUKに応じて')) {
                $lukBonus = (int)($attacker->luk * 0.5);
                $skillPowerInt += $lukBonus;
            }
            
            // 時空王等の追加攻撃確率
            if (str_contains($skill->description, '確率で追加')) {
                if (rand(1, 100) <= 30) {
                    $hitCount++; // ループを増やす
                }
            }

            if ($skill->power_multiplier > 0) {
                if (in_array($skill->damage_type, ['physical', 'gold', 'drop'], true)) {
                    $damage = $this->damageCalculator->calculatePhysicalDamage($attacker, $defender, $skillPowerInt, $isCrit, null, $overrideDef);
                } elseif ($skill->damage_type === 'magical') {
                    $damage = $this->damageCalculator->calculateMagicalDamage($attacker, $defender, $skillPowerInt, $isCrit, null, $overrideSpr);
                } elseif ($skill->damage_type === 'hybrid') {
                    $hybridAtk = (int)(($attacker->str + $attacker->mag) / 2);
                    if (str_contains($skill->description, '高い方依存')) {
                        $hybridAtk = max($attacker->str, $attacker->mag);
                    }
                    $damage = $this->damageCalculator->calculatePhysicalDamage($attacker, $defender, $skillPowerInt, $isCrit, $hybridAtk, $overrideDef);
                }
            }

            // 素材・ドロップ補正タイプの場合、戦闘後ボーナスを記録
            if (in_array($skill->damage_type, ['gold', 'drop'], true)) {
                $state->goldBonusPercent = max($state->goldBonusPercent ?? 0, $skill->gold_bonus_percent);
                $state->dropBonusPercent = max($state->dropBonusPercent ?? 0, $skill->drop_bonus_percent);
                if (str_contains((string) $skill->description, 'レア判定UP')) {
                    $state->rareBonusPercent = max($state->rareBonusPercent ?? 0, $skill->drop_bonus_percent);
                }
            }

            // ダメージ適用
            if ($damage > 0) {
                $defender->takeDamage($damage);
                $state->addLog("{$defender->name} に <span class=\"text-red-600 font-extrabold text-lg\">{$damage}</span> のダメージ！");
            }
            
            if ($defender->isDead()) break;
        }

        // 副効果の適用
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

        // デバフの適用（ボスは半減。単純化のため現在ステータスを直接下げる）
        $isBoss = isset($defender->originalModel->is_boss) ? $defender->originalModel->is_boss : false;
        $debuffRatio = $isBoss ? 0.5 : 1.0;
        
        if ($skill->enemy_def_down_percent > 0) {
            $effect = (int)($skill->enemy_def_down_percent * $debuffRatio);
            $state->addLog("{$defender->name} の防御力が {$effect}% 低下した！");
            $defender->def -= (int)($defender->baseDef * ($effect / 100));
            $defender->def = max(1, $defender->def);
        }
        if ($skill->enemy_spd_down_percent > 0) {
            $effect = (int)($skill->enemy_spd_down_percent * $debuffRatio);
            $state->addLog("{$defender->name} の素早さが {$effect}% 低下した！");
            $defender->agi -= (int)($defender->baseAgi * ($effect / 100));
            $defender->agi = max(1, $defender->agi);
        }
        if ($skill->enemy_spr_down_percent > 0) {
            $effect = (int)($skill->enemy_spr_down_percent * $debuffRatio);
            $state->addLog("{$defender->name} の精神力が {$effect}% 低下した！");
            $defender->spr -= (int)($defender->baseSpr * ($effect / 100));
            $defender->spr = max(1, $defender->spr);
        }

        // バフの適用
        if ($skill->damage_reduction_percent > 0) {
            $state->addLog("{$attacker->name} は次の被ダメージを軽減する構えをとった！");
            $attacker->damageReductionRate = $skill->damage_reduction_percent;
        }
        
        if ($skill->damage_type === 'support' && str_contains($skill->description, '上昇')) {
            $state->addLog("{$attacker->name} の攻撃力と魔法力が上昇した！");
            $attacker->str += (int)($attacker->baseStr * 0.05);
            $attacker->mag += (int)($attacker->baseMag * 0.05);
            $attacker->str = min($attacker->str, (int)($attacker->baseStr * 1.5)); // 上限1.5倍
            $attacker->mag = min($attacker->mag, (int)($attacker->baseMag * 1.5));
        }
    }

    private function executeJobArtAction(BattleActor $attacker, BattleActor $defender, BattleState $state, Skill $skill): void
    {
        $skillId = (int) $skill->id;
        $rate = (float) ($attacker->jobArtRates[$skillId] ?? 1.0);
        $origin = (string) ($attacker->jobArtOrigins[$skillId] ?? 'current');
        $template = (string) $skill->effect_template;
        $power = max(0, (int) round(((int) $skill->power ?: 100) * $rate));
        $prefix = $origin === 'inherited' ? '継承奥義' : '奥義';

        $state->jobArtUseCounts[$skillId] = (int) ($state->jobArtUseCounts[$skillId] ?? 0) + 1;
        if ((int) $skill->cooldown_turns > 0) {
            $state->jobArtCooldowns[$skillId] = (int) $skill->cooldown_turns;
        }

        $state->addLog("<span class=\"text-indigo-700 font-extrabold\">【{$prefix}】{$skill->name} が発動！</span>");

        match ($template) {
            'MAGICAL_DAMAGE' => $this->executeMagicalAttack($attacker, $defender, $state, $power),
            'HYBRID_DAMAGE' => $this->executeHybridJobArtAttack($attacker, $defender, $state, $power),
            'MULTI_HIT' => $this->executeMultiHitJobArt($attacker, $defender, $state, $power),
            'DAMAGE_BUFF' => $this->executeDamageBuffJobArt($attacker, $defender, $state, $power, $skill),
            'DAMAGE_DEBUFF' => $this->executeDamageDebuffJobArt($attacker, $defender, $state, $power, $skill),
            'SELF_BUFF' => $this->applySelfBuff($attacker, $state, $skill),
            'ENEMY_DEBUFF' => $this->applyEnemyDebuff($defender, $state, $skill),
            'GUARD_BARRIER' => $this->applyGuardBarrier($attacker, $state, $skill),
            'HEAL', 'HEAL_CLEANSE' => $this->applyJobArtHeal($attacker, $state, $skill, $rate),
            'DRAIN' => $this->executeDrainJobArt($attacker, $defender, $state, $power, $rate),
            'GUTS' => $this->applyGuts($attacker, $state),
            'REWARD_GOLD', 'REWARD_DROP', 'REWARD_MIXED' => $this->applyRewardJobArt($state, $skill, $rate),
            'TIME_CONTROL_CURRENT_ONLY' => $this->applyTimeControl($defender, $state, $skill),
            default => $this->executePhysicalAttack($attacker, $defender, $state, $power),
        };
    }

    private function executeMultiHitJobArt(BattleActor $attacker, BattleActor $defender, BattleState $state, int $power): void
    {
        $hitPower = max(60, (int) floor($power / 2));
        for ($i = 0; $i < 2; $i++) {
            $attacker->usesMagForNormalAttack()
                ? $this->executeMagicalAttack($attacker, $defender, $state, $hitPower)
                : $this->executePhysicalAttack($attacker, $defender, $state, $hitPower);
            if ($defender->isDead()) {
                break;
            }
        }
    }

    private function executeHybridJobArtAttack(BattleActor $attacker, BattleActor $defender, BattleState $state, int $power): void
    {
        if (!$this->damageCalculator->isHit($attacker, $defender)) {
            $state->addLog("{$attacker->name} の奥義！……しかし、{$defender->name} はかわした！");
            return;
        }

        $hybridAtk = (int) floor(($attacker->str + $attacker->mag) / 2);
        $damage = $this->damageCalculator->calculatePhysicalDamage($attacker, $defender, $power, false, $hybridAtk);
        $defender->takeDamage($damage);
        $state->addLog("{$defender->name} に <span class=\"text-fuchsia-600 font-extrabold text-lg\">{$damage}</span> の複合ダメージ！");
    }

    private function executeDamageBuffJobArt(BattleActor $attacker, BattleActor $defender, BattleState $state, int $power, Skill $skill): void
    {
        $attacker->usesMagForNormalAttack()
            ? $this->executeMagicalAttack($attacker, $defender, $state, $power)
            : $this->executePhysicalAttack($attacker, $defender, $state, $power);
        if (!$defender->isDead()) {
            $this->applySelfBuff($attacker, $state, $skill);
        }
    }

    private function executeDamageDebuffJobArt(BattleActor $attacker, BattleActor $defender, BattleState $state, int $power, Skill $skill): void
    {
        $attacker->usesMagForNormalAttack()
            ? $this->executeMagicalAttack($attacker, $defender, $state, $power)
            : $this->executePhysicalAttack($attacker, $defender, $state, $power);
        if (!$defender->isDead()) {
            $this->applyEnemyDebuff($defender, $state, $skill);
        }
    }

    private function applySelfBuff(BattleActor $attacker, BattleState $state, Skill $skill): void
    {
        $rate = $this->buffRate($skill);
        if ($attacker->usesMagForNormalAttack()) {
            $attacker->mag = min((int) floor($attacker->baseMag * 1.5), $attacker->mag + max(1, (int) floor($attacker->baseMag * $rate)));
            $attacker->spr = min((int) floor($attacker->baseSpr * 1.5), $attacker->spr + max(1, (int) floor($attacker->baseSpr * ($rate / 2))));
            $state->addLog("<span class=\"text-indigo-600 font-bold\">{$attacker->name} の魔力が高まった！</span>");
        } else {
            $attacker->str = min((int) floor($attacker->baseStr * 1.5), $attacker->str + max(1, (int) floor($attacker->baseStr * $rate)));
            $attacker->def = min((int) floor($attacker->baseDef * 1.5), $attacker->def + max(1, (int) floor($attacker->baseDef * ($rate / 2))));
            $state->addLog("<span class=\"text-indigo-600 font-bold\">{$attacker->name} の戦闘力が高まった！</span>");
        }
    }

    private function applyEnemyDebuff(BattleActor $defender, BattleState $state, Skill $skill): void
    {
        $rate = $this->buffRate($skill);
        $defender->def = max(1, $defender->def - max(1, (int) floor($defender->baseDef * $rate)));
        $defender->spr = max(1, $defender->spr - max(1, (int) floor($defender->baseSpr * ($rate / 2))));
        $state->addLog("<span class=\"text-violet-700 font-bold\">{$defender->name} の守りが乱れた！</span>");
    }

    private function applyGuardBarrier(BattleActor $attacker, BattleState $state, Skill $skill): void
    {
        $reduction = min(25, max(10, (int) floor(((int) $skill->power ?: 100) / 10)));
        $attacker->damageReductionRate = max($attacker->damageReductionRate, $reduction);
        $state->addLog("<span class=\"text-blue-700 font-bold\">{$attacker->name} は次の被ダメージを {$reduction}% 軽減する！</span>");
    }

    private function applyJobArtHeal(BattleActor $attacker, BattleState $state, Skill $skill, float $rate): void
    {
        $power = max(80, (int) ($skill->power ?: 100));
        $heal = max(1, (int) floor($attacker->spr * ($power / 100) * $rate));
        $attacker->healHp($heal);
        $state->addLog("<span class=\"text-emerald-600 font-bold\">HPが {$heal} 回復した！</span>");
    }

    private function executeDrainJobArt(BattleActor $attacker, BattleActor $defender, BattleState $state, int $power, float $rate): void
    {
        $beforeHp = $defender->hp;
        $this->executeMagicalAttack($attacker, $defender, $state, $power);
        $dealt = max(0, $beforeHp - $defender->hp);
        if ($dealt > 0) {
            $heal = max(1, (int) floor($dealt * 0.35 * $rate));
            $attacker->healHp($heal);
            $state->addLog("<span class=\"text-emerald-600 font-bold\">与えた力を吸収し、HPが {$heal} 回復した！</span>");
        }
    }

    private function applyGuts(BattleActor $attacker, BattleState $state): void
    {
        $attacker->gutsReady = true;
        $state->addLog("<span class=\"text-orange-700 font-bold\">{$attacker->name} は一度だけ踏みとどまる覚悟を固めた！</span>");
    }

    private function applyRewardJobArt(BattleState $state, Skill $skill, float $rate): void
    {
        $scope = (string) $skill->reward_scope;
        $base = max(1, (int) floor(((int) $skill->power ?: 100) / 20));
        $bonus = max(1, (int) floor($base * $rate));

        if (in_array($scope, ['gold', 'mixed'], true) || $skill->effect_template === 'REWARD_GOLD') {
            $state->goldBonusPercent = min(10, max($state->goldBonusPercent, $bonus));
        }
        if (in_array($scope, ['drop', 'material', 'mixed'], true) || in_array($skill->effect_template, ['REWARD_DROP', 'REWARD_MIXED'], true)) {
            $state->dropBonusPercent = min(8, max($state->dropBonusPercent, $bonus));
            $state->rareBonusPercent = min(8, max($state->rareBonusPercent, (int) floor($bonus / 2)));
        }

        $state->addLog("<span class=\"text-amber-700 font-bold\">探索勝利時の報酬判定が少し良くなった！</span>");
    }

    private function applyTimeControl(BattleActor $defender, BattleState $state, Skill $skill): void
    {
        $rate = max(0.05, $this->buffRate($skill));
        $defender->agi = max(1, $defender->agi - max(1, (int) floor($defender->baseAgi * $rate)));
        $state->addLog("<span class=\"text-sky-700 font-bold\">{$defender->name} の動きが鈍った！</span>");
    }

    private function buffRate(Skill $skill): float
    {
        $power = (int) ($skill->power ?: 100);
        if ($power >= 200) {
            return 0.20;
        }
        if ($power >= 140) {
            return 0.15;
        }
        return 0.10;
    }
}
