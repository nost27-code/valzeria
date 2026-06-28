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

    public function __construct(
        private readonly CharacterStatusService $statusService,
        private readonly DamageCalculator $damageCalculator,
        private readonly ArenaNpcRankingService $rankingService
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

        $this->executeNormalAttack($attacker, $defender, $state);
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

        app(PublicLogService::class)->addLog(
            'arena',
            "【闘技場】{$attacker->name}さんが放浪冒険者を破り、{$attackerOldRank}位から{$attackerNewRank}位へ駆け上がりました！",
            $attacker,
            2
        );

        if ($attackerOldRank > 10 && $attackerNewRank <= 10) {
            app(PublicLogService::class)->addLog(
                'arena',
                "【闘技場】{$attacker->name}さんが闘技場番付TOP10入りを果たしました！",
                $attacker,
                3
            );
        }
    }
}
