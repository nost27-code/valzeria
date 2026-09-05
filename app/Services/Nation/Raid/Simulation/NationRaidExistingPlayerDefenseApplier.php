<?php

namespace App\Services\Nation\Raid\Simulation;

use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageApplicationRequest;
use App\Services\Battle\DamageApplicationService;
use App\Services\Battle\DamageSourceType;
use App\Services\Battle\DirectAttackResolution;
use App\Services\Battle\HitResult;
use App\Services\JobArtV2ResourceService;
use App\Services\JobArtV2UltimateCounterplayService;
use App\Services\Nation\Raid\NationRaidEnemyDamageResult;
use App\Services\Nation\Raid\NationRaidIncomingDamageApplication;
use App\Services\Nation\Raid\NationRaidIncomingDamageApplier;

/**
 * レイド固有式でcap・最終軽減まで確定したdamageを、既存のguard/counter/GUTSへ渡す。
 *
 * レイドのdamage式や乱数は再計算せず、同じsource action idでHitを順番に適用するため、
 * guard/parryのaction単位消費とGUTSのHit単位適用を通常戦闘と共通化できる。
 */
final readonly class NationRaidExistingPlayerDefenseApplier implements NationRaidIncomingDamageApplier
{
    public function __construct(
        private BattleActor $player,
        private BattleActor $boss,
        private BattleState $state,
        private DamageApplicationService $damageApplication,
        private JobArtV2ResourceService $resourceService,
        private JobArtV2UltimateCounterplayService $ultimateCounterplayService,
    ) {}

    public function apply(
        NationRaidEnemyDamageResult $damage,
        string $enemyActionId,
        int $playerHpBeforeDamage,
        int $playerSpBeforeDamage,
    ): NationRaidIncomingDamageApplication {
        $this->synchronizeBeforeDamage($playerHpBeforeDamage, $playerSpBeforeDamage);
        $hpBefore = $this->player->hp;
        $bossHpBefore = $this->boss->hp;
        $guardBefore = $this->player->jobArtV2GuardState();
        $gutsReadyBefore = $this->player->gutsReady;
        $allocatedHits = $this->allocateFinalDamage($damage);
        $requestedDamage = 0;
        $gutsTriggered = false;

        $sourceActionId = $this->resourceService->beginAction($this->boss, $this->state)
            ?? $this->state->beginSourceAction();

        try {
            foreach ($allocatedHits as $hit) {
                if ($this->player->isDead()) {
                    break;
                }

                $result = $this->damageApplication->apply(new DamageApplicationRequest(
                    sourceActor: $this->boss,
                    targetActor: $this->player,
                    resolvedDamage: $hit['damage'],
                    sourceType: DamageSourceType::OTHER,
                    sourceId: $enemyActionId,
                    battleType: $this->state->battleType,
                    hitResult: HitResult::HIT,
                    hitIndex: $hit['index'],
                    hitCount: $hit['hit_count'],
                    battleState: $this->state,
                    directAttackResolution: DirectAttackResolution::fromDamageSource(
                        sourceActionId: $sourceActionId,
                        attacker: $this->boss,
                        target: $this->player,
                        hitResult: HitResult::HIT,
                        damageCategory: $hit['type'],
                        direct: true,
                        sourceType: DamageSourceType::OTHER,
                    ),
                ));
                $requestedDamage += $result->requestedDamage;
                if ($this->player->gutsJustTriggered) {
                    $gutsTriggered = true;
                    // 通常経路のlog表示に相当する消費済みmarker。profileへはtraceを残す。
                    $this->player->gutsJustTriggered = false;
                }
            }
        } finally {
            $this->resourceService->finishAction($this->boss, $this->state);
            $this->ultimateCounterplayService->finishAction($this->boss, $this->state);
        }

        $parry = $this->state->parryResult($this->player, $sourceActionId);
        $guardTrace = $this->state->damageTrace($this->player, $sourceActionId);
        $guardAfter = $this->player->jobArtV2GuardState();
        $hpAfter = $this->player->hp;
        $appliedEffects = $hpAfter < $hpBefore && ! $this->player->isDead()
            ? $damage->appliedEffects
            : [];
        $defenseTrace = [
            'source_action_id' => $sourceActionId,
            'legacy_reduction_rate' => $damage->finalReductionRate,
            'guard_charges_before' => $guardBefore?->charges ?? 0,
            'guard_charges_after' => $guardAfter?->charges ?? 0,
            'guard_consumed' => ($guardBefore?->charges ?? 0) > ($guardAfter?->charges ?? 0),
            'guard_rate' => $guardTrace?->guardRate ?? 0.0,
            'parry_eligible' => $parry?->eligible ?? false,
            'parry_rolled' => $parry?->rolled ?? false,
            'parry_succeeded' => $parry?->success ?? false,
            'guts_ready_before' => $gutsReadyBefore,
            'guts_triggered' => $gutsTriggered,
            'actual_hp_loss' => max(0, $hpBefore - $hpAfter),
        ];

        $resolvedDamage = new NationRaidEnemyDamageResult(
            beforeCap: $damage->beforeCap,
            cap: $damage->cap,
            afterCap: $damage->afterCap,
            finalReductionRate: $damage->finalReductionRate,
            finalDamage: $requestedDamage,
            hits: $damage->hits,
            appliedEffects: $appliedEffects,
            playerDefense: $defenseTrace,
        );

        return new NationRaidIncomingDamageApplication(
            damage: $resolvedDamage,
            playerHp: $this->player->hp,
            playerSp: $this->player->mp,
            counterDamage: max(0, $bossHpBefore - $this->boss->hp),
            defenseTrace: $defenseTrace,
        );
    }

    private function synchronizeBeforeDamage(int $hp, int $sp): void
    {
        $this->player->hp = max(0, min($this->player->maxHp, $hp));
        $this->player->mp = max(0, min($this->player->maxMp, $sp));
    }

    /**
     * @return list<array{index:int,type:string,damage:int,hit_count:int}>
     */
    private function allocateFinalDamage(NationRaidEnemyDamageResult $damage): array
    {
        $hits = array_values(array_filter(
            $damage->hits,
            static fn (mixed $hit): bool => is_array($hit)
                && ($hit['outcome'] ?? null) === 'hit'
                && (int) ($hit['damage'] ?? 0) > 0,
        ));
        if ($damage->finalDamage <= 0 || $hits === []) {
            return [];
        }

        $remainingDamage = $damage->finalDamage;
        $remainingWeight = array_sum(array_map(
            static fn (array $hit): int => (int) $hit['damage'],
            $hits,
        ));
        $remainingHits = count($hits);
        $allocated = [];

        foreach ($hits as $offset => $hit) {
            $weight = (int) $hit['damage'];
            if ($remainingHits === 1) {
                $share = $remainingDamage;
            } else {
                $minimumForLaterHits = $remainingDamage >= $remainingHits ? $remainingHits - 1 : 0;
                $share = (int) floor($remainingDamage * ($weight / max(1, $remainingWeight)));
                $share = max($remainingDamage >= $remainingHits ? 1 : 0, $share);
                $share = min($share, max(0, $remainingDamage - $minimumForLaterHits));
            }
            $remainingDamage -= $share;
            $remainingWeight -= $weight;
            $remainingHits--;

            if ($share <= 0) {
                continue;
            }
            $allocated[] = [
                'index' => (int) ($hit['index'] ?? ($offset + 1)),
                'type' => ($hit['type'] ?? null) === 'magical' ? 'magical' : 'physical',
                'damage' => $share,
                'hit_count' => count($hits),
            ];
        }

        return $allocated;
    }
}
