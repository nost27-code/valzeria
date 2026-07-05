<?php

namespace App\Services;

use App\Models\ArenaNpcLog;
use App\Models\ArenaNpcRanking;
use App\Models\ArenaRanking;
use App\Models\Character;
use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleResult;
use App\Services\Battle\BattleState;
use App\Services\Battle\BattleTypeAffinity;
use App\Services\Battle\DamageCalculator;
use Illuminate\Support\Facades\DB;

class ArenaNpcBattleService
{
    private const HIT_AGI_FACTOR = 0.08;
    private const MIN_HIT_RATE = 84;
    private const MAX_HIT_RATE = 97;
    private const TURN_SPEED_RANDOM = 2;
    private const NORMAL_POWER_MULTIPLIER = 125;
    private const MAX_TURNS = 30;
    private const PUBLIC_RANK_UP_LOG_MAX_RANK = 50;

    public function __construct(
        private readonly CharacterStatusService $statusService,
        private readonly DamageCalculator $damageCalculator,
        private readonly ArenaNpcRankingService $rankingService,
        private readonly JobArtBattleSupportService $jobArtBattleSupport
    ) {
    }

    public function executeBattle(Character $attacker, ArenaNpcRanking $npcRanking): BattleResult
    {
        $npcRanking->loadMissing('npc');

        $result = new BattleResult();
        $attackerActor = $this->makePlayerActor($attacker);
        $npcActor = $this->makeNpcActor($attacker, $npcRanking);
        $state = new BattleState($attackerActor, $npcActor, 'arena_npc');
        $state->maxTurns = self::MAX_TURNS;

        $state->addLog("【闘技場】{$attackerActor->name} が {$npcActor->name} に勝負を挑んだ！");
        $state->addLog("<span class=\"text-slate-600 font-bold\">相手は酒場でも噂される放浪冒険者だ。詳しい実力は分からない。</span>");

        while (!$state->isBattleEnded() && $state->turnCount < self::MAX_TURNS) {
            $state->turnCount++;
            $state->addLog("<br><br>--- ターン {$state->turnCount} ---");

            $attackerSpeed = $attackerActor->agi + random_int(0, self::TURN_SPEED_RANDOM);
            $npcSpeed = $npcActor->agi + random_int(0, self::TURN_SPEED_RANDOM);

            if ($attackerSpeed >= $npcSpeed) {
                $this->executeAction($attackerActor, $npcActor, $state);
                if ($state->isBattleEnded()) {
                    break;
                }
                $this->executeAction($npcActor, $attackerActor, $state);
            } else {
                $this->executeAction($npcActor, $attackerActor, $state);
                if ($state->isBattleEnded()) {
                    break;
                }
                $this->executeAction($attackerActor, $npcActor, $state);
            }
        }

        $isAttackerWin = false;
        if ($npcActor->isDead()) {
            $state->addLog("<br><span class=\"text-black font-extrabold text-xl\">決着！{$attackerActor->name}は、{$npcActor->name}を倒した！</span>");
            $result->result = 'victory';
            $isAttackerWin = true;
        } elseif (!$attackerActor->isDead() && $attackerActor->hp > $npcActor->hp) {
            $state->addLog("<br><span class=\"text-black font-extrabold text-xl\">判定勝利！{$attackerActor->name}が優勢のまま押し切った！</span>");
            $result->result = 'victory';
            $isAttackerWin = true;
        } else {
            $state->addLog("<br><span class=\"text-black font-extrabold text-xl\">決着！{$npcActor->name}が防衛に成功した！</span>");
            $result->result = 'defeat';
        }

        $result->logs = $state->logs;
        $result->playerHpAfter = $attackerActor->hp;
        $result->playerMpAfter = $attackerActor->mp;

        DB::transaction(function () use ($attacker, $npcRanking, $isAttackerWin): void {
            $attackerRanking = ArenaRanking::where('character_id', $attacker->id)->lockForUpdate()->firstOrFail();
            $npcRanking = ArenaNpcRanking::whereKey($npcRanking->id)->lockForUpdate()->firstOrFail();

            $attackerOldRank = (int) $attackerRanking->rank;
            $npcOldRank = (int) $npcRanking->rank;
            $attackerNewRank = $attackerOldRank;
            $npcNewRank = $npcOldRank;

            if ($isAttackerWin) {
                $attackerRanking->wins++;
                $npcRanking->losses++;

                if ($npcOldRank < $attackerOldRank) {
                    $temporaryRank = -1 * (int) $attackerRanking->id;
                    $attackerRanking->rank = $temporaryRank;
                    $attackerRanking->save();

                    $this->rankingService->shiftCombinedRanksDown($npcOldRank, $attackerOldRank - 1, (int) $attacker->id);

                    $attackerRanking->rank = $npcOldRank;
                    $attackerNewRank = $npcOldRank;
                    $npcRanking->refresh();
                    $npcNewRank = (int) $npcRanking->rank;
                }
            } else {
                $attackerRanking->losses++;
                $this->rankingService->recordNpcWin($npcRanking);
            }

            $attackerRanking->save();
            $npcRanking->save();

            $log = ArenaNpcLog::create([
                'attacker_id' => $attacker->id,
                'arena_npc_ranking_id' => $npcRanking->id,
                'npc_id' => $npcRanking->npc_id,
                'is_attacker_win' => $isAttackerWin,
                'attacker_old_rank' => $attackerOldRank,
                'attacker_new_rank' => $attackerNewRank,
                'defender_old_rank' => $npcOldRank,
                'defender_new_rank' => $npcNewRank,
            ]);

            $this->publishPublicLogs($attacker, $isAttackerWin, $attackerOldRank, $attackerNewRank, $log);
        });

        return $result;
    }

    public function npcStats(Character $attacker, ArenaNpcRanking $npcRanking): array
    {
        $playerStats = $this->statusService->getFinalStats($attacker);
        $rankGap = max(1, (int) $attacker->arenaRanking?->rank - (int) $npcRanking->rank);
        $powerRate = min(1.18, 1.02 + ($rankGap * 0.025));
        $profile = (string) $npcRanking->battle_profile;

        $stats = [
            'max_hp' => (int) floor((int) $playerStats['max_hp'] * $powerRate),
            'max_mp' => (int) floor((int) ($playerStats['max_mp'] ?? 0) * 0.8),
            'str' => (int) floor((int) $playerStats['str'] * $powerRate),
            'def' => (int) floor((int) $playerStats['def'] * $powerRate),
            'agi' => (int) floor((int) $playerStats['agi'] * $powerRate),
            'mag' => (int) floor((int) $playerStats['mag'] * $powerRate),
            'spr' => (int) floor((int) $playerStats['spr'] * $powerRate),
            'luk' => (int) floor((int) $playerStats['luk'] * $powerRate),
        ];

        match ($profile) {
            'physical' => $stats['str'] = (int) floor($stats['str'] * 1.12),
            'guard' => $stats['def'] = (int) floor($stats['def'] * 1.15),
            'speed' => $stats['agi'] = (int) floor($stats['agi'] * 1.15),
            'magical' => $stats['mag'] = (int) floor($stats['mag'] * 1.12),
            default => $stats['luk'] = (int) floor($stats['luk'] * 1.08),
        };

        return array_map(fn (int $value): int => max(1, $value), $stats);
    }

    private function makePlayerActor(Character $character): BattleActor
    {
        $stats = $this->statusService->getFinalStats($character);
        $job = $character->relationLoaded('currentJob')
            ? $character->currentJob
            : $character->currentJob()->with('skill')->first();

        $actor = new BattleActor($character->name, true, [
            'hp' => $stats['max_hp'],
            'max_hp' => $stats['max_hp'],
            'mp' => $stats['max_mp'] ?? 0,
            'max_mp' => $stats['max_mp'] ?? 0,
            'str' => $stats['str'],
            'def' => $stats['def'],
            'agi' => $stats['agi'],
            'mag' => $stats['mag'],
            'spr' => $stats['spr'],
            'luk' => $stats['luk'],
            'battle_type_weights' => $this->battleTypeWeights($job),
            'normal_attack_type' => $this->normalAttackType($job),
        ], clone $character);

        $actor->skill = $job?->skill;
        $actor->jobKey = $job?->key;
        $this->jobArtBattleSupport->attachBossSet($actor, $character, 'champ');

        return $actor;
    }

    private function makeNpcActor(Character $attacker, ArenaNpcRanking $npcRanking): BattleActor
    {
        $stats = $this->npcStats($attacker, $npcRanking);
        $npc = $npcRanking->npc;
        $weights = match ((string) $npcRanking->battle_profile) {
            'speed' => ['physical' => 0.5, 'speed' => 1.0, 'magical' => 0.0],
            'magical' => ['physical' => 0.2, 'speed' => 0.0, 'magical' => 1.0],
            default => ['physical' => 1.0, 'speed' => 0.0, 'magical' => 0.0],
        };

        return new BattleActor($npc?->npc_name ?? '放浪冒険者', false, [
            'hp' => $stats['max_hp'],
            'max_hp' => $stats['max_hp'],
            'mp' => $stats['max_mp'],
            'max_mp' => $stats['max_mp'],
            'str' => $stats['str'],
            'def' => $stats['def'],
            'agi' => $stats['agi'],
            'mag' => $stats['mag'],
            'spr' => $stats['spr'],
            'luk' => $stats['luk'],
            'battle_type_weights' => $weights,
            'normal_attack_type' => (string) $npcRanking->battle_profile === 'magical' ? 'magical' : 'physical',
        ], clone $npcRanking);
    }

    private function executeAction(BattleActor $attacker, BattleActor $defender, BattleState $state): void
    {
        $attacker->isDefending = false;
        $attacker->damageReductionRate = 0;

        if ($attacker->isPlayer) {
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
                return;
            }

            if ($attacker->skill && random_int(1, 100) <= $attacker->skill->effectiveActivationRate()) {
                $spCost = $attacker->skill->specialSkillSpCostForMaxSp($attacker->maxMp);
                if ($attacker->mp >= $spCost) {
                    $attacker->mp -= $spCost;
                    $this->executeSkillAction($attacker, $defender, $state, $attacker->skill);
                    return;
                }
            }
        }

        $this->executeNormalAttack($attacker, $defender, $state);
    }

    private function executeSkillAction(
        BattleActor $attacker,
        BattleActor $defender,
        BattleState $state,
        Skill $skill,
        bool $addOpeningLog = true
    ): void {
        if ($addOpeningLog) {
            $state->addLog("<span class=\"text-blue-600 font-bold\">【必殺技】{$attacker->name} の必殺技、{$skill->name} が発動！</span>");
        }

        $hitCount = max(1, (int) $skill->hit_count);
        if ((int) $skill->hit_count === 0 && in_array($skill->damage_type, ['heal', 'support'], true)) {
            $hitCount = 1;
        }

        $affinityMultiplier = BattleTypeAffinity::multiplier($attacker->battleTypeWeights, $defender->battleTypeWeights);
        $totalDamage = 0;
        for ($i = 0; $i < $hitCount; $i++) {
            $damage = 0;
            $power = max(0, (int) round((float) $skill->power_multiplier * 100));
            $overrideDef = null;
            $overrideSpr = null;

            if ((int) $skill->def_ignore_percent > 0) {
                $overrideDef = (int) floor($defender->def * (1 - ((int) $skill->def_ignore_percent / 100)));
                $overrideSpr = (int) floor($defender->spr * (1 - ((int) $skill->def_ignore_percent / 100)));
            }

            if (str_contains((string) $skill->description, 'LUKに応じて')) {
                $power += (int) floor($attacker->luk * 0.5);
            }

            if ((float) $skill->power_multiplier > 0) {
                if (in_array($skill->damage_type, ['physical', 'gold', 'drop'], true)) {
                    $damage = $this->damageCalculator->calculateRankBattleDamage(
                        $attacker,
                        $defender,
                        'physical',
                        $power,
                        false,
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
                        $power,
                        false,
                        $affinityMultiplier,
                        null,
                        $overrideDef,
                        $overrideSpr,
                        true,
                        $hitCount
                    );
                } elseif ($skill->damage_type === 'hybrid') {
                    $hybridAtk = str_contains((string) $skill->description, '高い方依存')
                        ? max($attacker->str, $attacker->mag)
                        : (int) floor(($attacker->str + $attacker->mag) / 2);
                    $damage = $this->damageCalculator->calculateRankBattleDamage(
                        $attacker,
                        $defender,
                        'physical',
                        $power,
                        false,
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
                $state->addLog("{$defender->name} に <span class=\"text-red-600 font-extrabold text-lg\">{$damage}</span> のダメージ！");
            }

            if ($defender->isDead()) {
                break;
            }
        }

        if ($skill->isJobArt()) {
            $this->applyJobArtTemplateEffects($attacker, $defender, $state, $skill, $totalDamage);
        }

        if ((int) $skill->heal_percent > 0) {
            $healAmount = (int) floor($attacker->maxHp * ((int) $skill->heal_percent / 100));
            $attacker->healHp($healAmount);
            $state->addLog("<span class=\"text-green-600 font-bold\">{$attacker->name} の傷が {$healAmount} 回復した！</span>");
        }

        if ((int) $skill->mp_recover_percent > 0 && $attacker->maxMp > 0) {
            $mpHealAmount = (int) floor($attacker->maxMp * ((int) $skill->mp_recover_percent / 100));
            $attacker->mp = min($attacker->maxMp, $attacker->mp + $mpHealAmount);
            $state->addLog("<span class=\"text-blue-500 font-bold\">{$attacker->name} はSPを {$mpHealAmount} 回復した！</span>");
        }

        if ((int) $skill->damage_reduction_percent > 0) {
            $attacker->damageReductionRate = (int) $skill->damage_reduction_percent;
            $state->addLog("{$attacker->name} は次の被ダメージを軽減する構えをとった！");
        }

        if (!$skill->isJobArt() && $skill->damage_type === 'support' && str_contains((string) $skill->description, '上昇')) {
            $attacker->str = min((int) floor($attacker->baseStr * 1.5), $attacker->str + (int) floor($attacker->baseStr * 0.05));
            $attacker->mag = min((int) floor($attacker->baseMag * 1.5), $attacker->mag + (int) floor($attacker->baseMag * 0.05));
            $state->addLog("{$attacker->name} の攻撃力と魔法力が上昇した！");
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
        $power = max(80, (int) ($skill->power ?: 100));

        if (in_array($template, ['HEAL', 'HEAL_CLEANSE'], true)) {
            $heal = max(1, (int) floor($attacker->spr * ($power / 100)));
            $attacker->healHp($heal);
            $state->addLog("<span class=\"text-emerald-600 font-bold\">HPが {$heal} 回復した！</span>");
        }

        if ($template === 'DRAIN' && $totalDamage > 0 && str_contains((string) $skill->description, 'HP')) {
            $heal = max(1, (int) floor($totalDamage * 0.35));
            $attacker->healHp($heal);
            $state->addLog("<span class=\"text-emerald-600 font-bold\">与えた力を吸収し、HPが {$heal} 回復した！</span>");
        }

        if ($template === 'GUTS') {
            $attacker->gutsReady = true;
            $state->addLog("<span class=\"text-orange-700 font-bold\">{$attacker->name} は一度だけ踏みとどまる覚悟を固めた！</span>");
        }

        if (in_array($template, ['GUARD_BARRIER', 'DAMAGE_GUARD_BARRIER'], true)) {
            $reduction = min(25, max(10, (int) floor($power / 10)));
            $attacker->damageReductionRate = max($attacker->damageReductionRate, $reduction);
            $state->addLog("<span class=\"text-blue-700 font-bold\">{$attacker->name} は次の被ダメージを {$reduction}% 軽減する！</span>");
        }

        if (in_array($template, ['SELF_BUFF', 'DAMAGE_BUFF', 'MAGICAL_DAMAGE_BUFF'], true)) {
            $attacker->str = min((int) floor($attacker->baseStr * 1.5), $attacker->str + max(1, (int) floor($attacker->baseStr * 0.10)));
            $attacker->mag = min((int) floor($attacker->baseMag * 1.5), $attacker->mag + max(1, (int) floor($attacker->baseMag * 0.10)));
            $state->addLog("<span class=\"text-indigo-600 font-bold\">{$attacker->name} の戦闘力が高まった！</span>");
        }

        if (in_array($template, ['ENEMY_DEBUFF', 'DAMAGE_DEBUFF'], true)) {
            $defender->def = max(1, $defender->def - max(1, (int) floor($defender->baseDef * 0.10)));
            $defender->spr = max(1, $defender->spr - max(1, (int) floor($defender->baseSpr * 0.05)));
            $state->addLog("<span class=\"text-violet-700 font-bold\">{$defender->name} の守りが乱れた！</span>");
        }

        if ($template === 'TIME_CONTROL_CURRENT_ONLY') {
            $defender->agi = max(1, $defender->agi - max(1, (int) floor($defender->baseAgi * 0.10)));
            $state->addLog("<span class=\"text-sky-700 font-bold\">{$defender->name} の動きが鈍った！</span>");
        }
    }

    private function executeNormalAttack(BattleActor $attacker, BattleActor $defender, BattleState $state): void
    {
        if (!$this->damageCalculator->isHit(
            $attacker,
            $defender,
            100,
            self::HIT_AGI_FACTOR,
            self::MIN_HIT_RATE,
            self::MAX_HIT_RATE
        )) {
            $state->addLog("{$attacker->name} の攻撃！……しかし、{$defender->name} はかわした！");
            return;
        }

        $attackType = $attacker->usesMagForNormalAttack() ? 'magical' : 'physical';
        $isCritical = $this->damageCalculator->isRankBattleCritical($attacker, $defender);
        $affinityMultiplier = BattleTypeAffinity::multiplier($attacker->battleTypeWeights, $defender->battleTypeWeights);
        $damage = $this->damageCalculator->calculateRankBattleDamage(
            $attacker,
            $defender,
            $attackType,
            self::NORMAL_POWER_MULTIPLIER,
            $isCritical,
            $affinityMultiplier
        );

        $defender->takeDamage($damage);

        $critText = $isCritical ? "<span class=\"text-orange-500 font-bold\">【痛恨の一撃！】</span>" : '';
        $damageClass = $attackType === 'magical' ? 'text-purple-600' : 'text-red-600';
        $state->addLog("{$attacker->name} の攻撃！ {$critText} {$defender->name} に <span class=\"{$damageClass} font-extrabold text-lg\">{$damage}</span> のダメージ！");
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

        return in_array($type, ['physical', 'magical'], true) ? $type : 'physical';
    }

    private function publishPublicLogs(
        Character $attacker,
        bool $isAttackerWin,
        int $attackerOldRank,
        int $attackerNewRank,
        ArenaNpcLog $log
    ): void {
        if (!$isAttackerWin || $attackerNewRank >= $attackerOldRank) {
            return;
        }

        $logService = app(PublicLogService::class);
        if ($attackerNewRank <= self::PUBLIC_RANK_UP_LOG_MAX_RANK) {
            $logService->addLog(
                'arena',
                "【闘技場】{$attacker->name}さんが放浪冒険者を破り、{$attackerOldRank}位から{$attackerNewRank}位へ駆け上がりました！",
                $attacker,
                2
            );
        }

        if ($attackerOldRank > 10 && $attackerNewRank <= 10) {
            $logService->addLog(
                'arena',
                "【闘技場】{$attacker->name}さんが闘技場番付TOP10入りを果たしました！",
                $attacker,
                3
            );
        }
    }
}
