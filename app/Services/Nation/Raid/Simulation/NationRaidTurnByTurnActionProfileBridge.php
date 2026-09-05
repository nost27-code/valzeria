<?php

namespace App\Services\Nation\Raid\Simulation;

use App\Models\Character;
use App\Models\Enemy;
use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageApplicationResult;
use App\Services\Battle\DamageSourceType;
use App\Services\Battle\HitResult;
use App\Services\Battle\ScopedBattleRandomizer;
use App\Services\BattleService;
use App\Services\JobArtV2DeckRoleResolution;
use App\Services\Nation\Raid\NationRaidBattleEngine;
use App\Services\Nation\Raid\NationRaidBattleInput;
use App\Services\Nation\Raid\NationRaidBossTurnPrompt;
use App\Services\Nation\Raid\NationRaidBossTurnResolution;
use App\Services\Nation\Raid\NationRaidBossTurnSession;
use App\Services\Nation\Raid\NationRaidPlayerActionSnapshot;
use App\Services\Nation\Raid\NationRaidPlayerTurnState;
use App\Services\Nation\Raid\NationRaidRules;
use LogicException;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * 既存BattleServiceのplayer側だけを1手番ずつ実行し、同じPhase 1 boss sessionへ返すbridge。
 *
 * 選択時だけraid予告を公開し、選択直後に閉じる。既存PvE対抗効果の適用経路は走らせず、
 * exact identityと同じ行動のdamage/resource/mark状態をPhase 1へ渡す。
 */
class NationRaidTurnByTurnActionProfileBridge extends BattleService
{
    /** @var array<int, array<string, array{kind:string,damage:int,hit_count:int,defense_ignore_50_damage:?int}>> */
    private array $sourcesByTurn = [];

    /** @var list<array<string, mixed>> */
    private array $actions = [];

    /** @var list<array<string, mixed>> 実行後の計測値。戦技選択やengine入力に使用しない。 */
    private array $playerTurnMetrics = [];

    private array $executedPlayerAction = [];

    private int $observedHealing = 0;

    /** @var array<int, int> */
    private array $selectionCallsByTurn = [];

    /** @var list<int> */
    private array $selectionOrder = [];

    /** @var array<int, bool> */
    private array $telegraphClosedAfterSelection = [];

    private ?NationRaidBossTurnSession $raidSession = null;

    private ?NationRaidBattleInput $raidInput = null;

    private ?NationRaidBossTurnPrompt $currentPrompt = null;

    private ?string $selectedCounterplayIdentity = null;

    private ?BattleActor $lastBossActor = null;

    /**
     * 入力のstage/form/seed/作戦を正本にし、Character本体へwriteせず1出撃を解決する。
     */
    public function resolveProfile(
        Character $character,
        NationRaidBattleInput $input,
        ?array $preparedPlayer = null,
    ): NationRaidTurnByTurnBridgeResult {
        if ($this->raidSession !== null) {
            throw new LogicException('Raid turn-by-turn bridge is already resolving a profile.');
        }

        $stats = $preparedPlayer['stats'] ?? $this->statusService->getFinalStats($character);
        $this->resetBridgeState($input);
        $randomizer = new Randomizer(new Mt19937($input->seed));
        $this->useScopedBattleRandomizer($randomizer);

        try {
            $playerBattleResult = ScopedBattleRandomizer::run($randomizer, fn () => $this->executeBattle(
                $character,
                $this->raidBoss(),
                0,
                [
                    'persist_character_state' => false,
                    'prepared_player_actor' => $preparedPlayer,
                    'rewards_enabled' => false,
                    'exploration_support_enabled' => false,
                    'auto_unequip_invalid_items' => false,
                    'starting_hp' => (int) $stats['max_hp'],
                    'starting_mp' => (int) ($stats['max_mp'] ?? 0),
                    'job_art_context' => 'boss',
                    'battle_type' => NationRaidRules::BATTLE_TYPE,
                    'max_turns' => NationRaidRules::MAX_TURNS,
                    'force_player_first' => true,
                ],
            ));

            if (! $this->raidSession->finished()) {
                throw new LogicException('Raid turn-by-turn bridge ended before the boss session.');
            }

            $battleResult = $this->raidSession->result();
            $expectedTurns = range(1, $battleResult->turnsCompleted);
            if ($this->selectionOrder !== $expectedTurns) {
                throw new LogicException('Raid player selection was not called exactly once in turn order.');
            }
            foreach ($expectedTurns as $turn) {
                if (($this->selectionCallsByTurn[$turn] ?? 0) !== 1) {
                    throw new LogicException("Raid player selection count is invalid for turn {$turn}.");
                }
            }

            $boss = $this->lastBossActor;
            if (! $boss instanceof BattleActor) {
                throw new LogicException('Raid bridge did not retain the boss actor for isolation checks.');
            }
            $counterplayState = $boss->existingJobArtV2UltimateCounterplayState();

            return new NationRaidTurnByTurnBridgeResult(
                battleResult: $battleResult,
                actions: $this->actions,
                selectionCallsByTurn: $this->selectionCallsByTurn,
                selectionOrder: $this->selectionOrder,
                telegraphClosedAfterSelection: $this->telegraphClosedAfterSelection,
                bossIsolation: [
                    'guard_state_absent' => $boss->jobArtV2GuardState() === null,
                    'counter_stance_absent' => $boss->counterStanceState() === null,
                    'timed_effect_count' => count($boss->jobArtV2TimedEffects()),
                    'resource_slow_charges' => (int) ($counterplayState?->resourceGainPenaltyCharges ?? 0),
                    'virtual_max_hp' => $boss->maxHp,
                    'species_keys' => $boss->speciesKeys,
                ],
                knownGaps: [],
                playerBattleLogs: array_values(array_map('strval', $playerBattleResult->logs)),
                playerTurnMetrics: $this->playerTurnMetrics,
            );
        } finally {
            $this->raidSession = null;
            $this->raidInput = null;
            $this->currentPrompt = null;
        }
    }

    protected function executeAction(BattleActor $attacker, BattleActor $defender, BattleState $state): void
    {
        if (! $attacker->isPlayer) {
            // ボス側はPhase 1 sessionが解決済み。既存enemy AIを二重実行しない。
            return;
        }
        if ($this->raidSession === null || $this->raidInput === null) {
            throw new LogicException('Raid bridge player action started without an active session.');
        }

        $turn = $state->turnCount;
        $prompt = $this->raidSession->beginTurn();
        if ($prompt->turn !== $turn) {
            throw new LogicException('Raid bridge and BattleService turn counters diverged.');
        }

        $this->currentPrompt = $prompt;
        $this->selectedCounterplayIdentity = null;
        $this->sourcesByTurn[$turn] = [];
        // 現行player engineの割合効果は仮想最大HP 100,000を参照する。
        // 現在HPは適用中だけ大容量damage sinkのまま維持し、20手を途中終了させない。
        $defender->maxHp = NationRaidRules::VIRTUAL_MAX_HP;
        $state->valmonAssistRolled = true;
        $marks = [
            'hunting' => $this->jobArtV2ProgressionService->huntingMarkCountFor($defender, $attacker),
            'break' => $this->jobArtV2ProgressionService->breakMarkCountFor($defender, $attacker),
        ];
        $beforeDebuffs = $this->debuffStateKeys($defender);
        $hpBefore = $attacker->hp;
        $spBefore = $attacker->mp;
        $this->executedPlayerAction = [
            'action_type' => 'normal', 'skill_id' => null, 'skill_name' => '',
            'exact_identity' => null, 'sp_spent' => 0,
        ];

        parent::executeAction($attacker, $defender, $state);
        $hpAfterPlayerAction = $attacker->hp;

        $afterDebuffs = $this->debuffStateKeys($defender);
        $sources = array_values($this->sourcesByTurn[$turn]);
        $action = new NationRaidPlayerActionSnapshot(
            turn: $turn,
            damageSources: $sources,
            selectedCounterplayIdentity: $this->selectedCounterplayIdentity,
            bossDebuffKeysApplied: array_values(array_diff($afterDebuffs, $beforeDebuffs)),
            counterplayHit: array_sum(array_column($sources, 'damage')) > 0,
            huntingMarkCount: $marks['hunting'],
            breakMarkCount: $marks['break'],
        );

        $resolution = $this->raidSession->resolveTurn(
            $action,
            $this->livePlayerState($attacker, $defender, $state),
        );
        $this->synchronizePlayerAfterBossTurn($attacker, $defender, $state, $resolution);
        $this->playerTurnMetrics[] = $this->executedPlayerAction + [
            'turn' => $turn,
            'player_hp_before' => $hpBefore, 'player_sp_before' => $spBefore,
            'player_hp_after_action' => $hpAfterPlayerAction,
            'player_hp_after' => $attacker->hp, 'player_sp_after' => $attacker->mp,
            // 開始時/手番間の定期回復も次の観測境界へ含める。HP純増から推測しない。
            'healing' => $attacker->totalHpHealed - $this->observedHealing,
        ];
        $this->observedHealing = $attacker->totalHpHealed;
        $this->actions[] = $this->actionArray($action);
        $this->lastBossActor = $defender;
        $this->currentPrompt = null;
    }

    protected function observeExecutedPlayerJobArt(BattleActor $actor, BattleState $state, Skill $skill, int $spSpent): void
    {
        $this->executedPlayerAction = [
            'action_type' => 'job_art', 'skill_id' => $skill->id, 'skill_name' => $skill->name,
            'exact_identity' => $skill->job_id.':'.$skill->learn_rank.':'.$skill->name,
            'sp_spent' => $spSpent,
        ];
    }

    protected function executeEnemyAction(BattleActor $attacker, BattleActor $defender, BattleState $state): void
    {
        // Phase 1 sessionが同じturnのenemy actionを解決しているためno-op。
    }

    protected function selectJobArtForAction(BattleActor $attacker, BattleState $state): ?Skill
    {
        $prompt = $this->currentPrompt;
        if (! $attacker->isPlayer || ! $prompt instanceof NationRaidBossTurnPrompt || $this->raidInput === null) {
            return parent::selectJobArtForAction($attacker, $state);
        }

        $turn = $state->turnCount;
        $this->selectionCallsByTurn[$turn] = ($this->selectionCallsByTurn[$turn] ?? 0) + 1;
        $this->selectionOrder[] = $turn;
        if ($this->selectionCallsByTurn[$turn] !== 1) {
            throw new LogicException("Raid player selection was called more than once on turn {$turn}.");
        }

        $state->pendingEnemyActionId = $prompt->selectionPendingActionId();
        $state->enemyTelegraphContext = $prompt->selectionContext($this->raidInput->strategy);
        $damageSinkHp = $state->enemy->hp;
        // HP条件付きauto selectionだけはPhase 1の仮想残HPを参照する。
        $state->enemy->hp = $prompt->bossVirtualHp;

        try {
            $skill = $this->jobArtV2FeatureGate->usesDynamicSingle($attacker)
                ? $this->jobArtV2SelectionService->selectForTurn(
                    $attacker,
                    $state,
                    null,
                    $this->raidInput->strategy === NationRaidRules::STRATEGY_BOSS_SET ? null : fn (
                        array $candidates,
                        callable $isEligible,
                        callable $isReadyUltimate,
                        callable $isResponseCandidate,
                    ): array => app(NationRaidStrategyCandidateOrderer::class)->order(
                        strategy: $this->raidInput->strategy,
                        actor: $attacker,
                        candidates: $candidates,
                        isEligible: $isEligible,
                        isReadyUltimate: $isReadyUltimate,
                        isResponseCandidate: $isResponseCandidate,
                    ),
                )->skill
                : parent::selectJobArtForAction($attacker, $state);
        } finally {
            // beginJobArtCast()より前に必ず閉じ、通常PvE対抗効果を適用させない。
            $state->enemy->hp = $damageSinkHp;
            $state->pendingEnemyActionId = null;
            $state->pendingEnemyActionTurns = 0;
            $state->enemyTelegraphContext = null;
        }

        $this->telegraphClosedAfterSelection[$turn] = ! $this->jobArtV2UltimateCounterplayService
            ->pveTelegraphAvailable($attacker, $state);
        $this->selectedCounterplayIdentity = null;
        if ($skill instanceof Skill) {
            $identity = JobArtV2DeckRoleResolution::artKey($skill);
            if (app(NationRaidRules::class)->counterplayArt($identity) !== null) {
                $this->selectedCounterplayIdentity = $identity;
            }
        }

        return $skill;
    }

    protected function canExecuteSelectedJobArt(BattleActor $attacker, BattleState $state, Skill $skill): bool
    {
        $prompt = $this->currentPrompt;
        if (! $attacker->isPlayer || ! $prompt instanceof NationRaidBossTurnPrompt || $this->raidInput === null) {
            return parent::canExecuteSelectedJobArt($attacker, $state, $skill);
        }

        // selectForTurnは再実行しない。実行直前の適格性確認にも同じ予告/仮想HPを見せ、
        // 効果適用に入る前に必ず閉じる（F3/F10の通常PvE対抗効果を流入させない）。
        $state->pendingEnemyActionId = $prompt->selectionPendingActionId();
        $state->enemyTelegraphContext = $prompt->selectionContext($this->raidInput->strategy);
        $damageSinkHp = $state->enemy->hp;
        $state->enemy->hp = $prompt->bossVirtualHp;
        try {
            $eligible = parent::canExecuteSelectedJobArt($attacker, $state, $skill);
            if (! $eligible) {
                $this->selectedCounterplayIdentity = null;
            }

            return $eligible;
        } finally {
            $state->enemy->hp = $damageSinkHp;
            $state->pendingEnemyActionId = null;
            $state->pendingEnemyActionTurns = 0;
            $state->enemyTelegraphContext = null;
        }
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
        $result = parent::applyResolvedDamage(
            $source,
            $target,
            $state,
            $damage,
            $sourceType,
            $sourceId,
            $hitResult,
            $hitIndex,
            $hitCount,
            $isDirect,
            $damageCategory,
        );

        if ($source !== $state->player || $target !== $state->enemy || $damage <= 0) {
            return $result;
        }

        $resolvedDamage = max(0, (int) ($result?->requestedDamage ?? $damage));
        $kind = $this->raidDamageKind($sourceType);
        $groupKey = implode('|', [
            $kind,
            $sourceType->value,
            (string) ($sourceId ?? 'none'),
            (string) ($state->currentSourceActionId() ?? 0),
            (string) ($damageCategory ?? 'none'),
        ]);
        $turn = $state->turnCount;
        $existing = $this->sourcesByTurn[$turn][$groupKey] ?? [
            'kind' => $kind,
            'damage' => 0,
            'hit_count' => max(1, $hitCount),
            'defense_ignore_50_damage' => 0,
        ];
        $existing['damage'] += $resolvedDamage;
        $existing['hit_count'] = max($existing['hit_count'], $hitCount);
        $existing['defense_ignore_50_damage'] += $this->defenseIgnoreDamage(
            $resolvedDamage,
            $source,
            $target,
            $damageCategory,
            $kind,
        );
        $this->sourcesByTurn[$turn][$groupKey] = $existing;

        return $result;
    }

    private function resetBridgeState(NationRaidBattleInput $input): void
    {
        $this->raidInput = $input;
        $this->raidSession = app(NationRaidBattleEngine::class)->startSession($input);
        $this->sourcesByTurn = [];
        $this->actions = [];
        $this->playerTurnMetrics = [];
        $this->executedPlayerAction = [];
        $this->observedHealing = 0;
        $this->selectionCallsByTurn = [];
        $this->selectionOrder = [];
        $this->telegraphClosedAfterSelection = [];
        $this->selectedCounterplayIdentity = null;
        $this->lastBossActor = null;
    }

    private function livePlayerState(
        BattleActor $player,
        BattleActor $boss,
        BattleState $state,
    ): NationRaidPlayerTurnState {
        $hitChance = $this->damageCalculator->calculateHitChance($boss, $player, 100, 0.5, 82, 99);
        $hitChance += $this->jobArtV2FieldService->accuracyDelta($boss, $state);
        $activeEvasion = $this->jobArtV2ProgressionService->activeEvasionRate($boss, $player) * 100;

        $legacyMultiplier = ($player->isDefending ? 0.50 : 1.0)
            * (1 - max(0.0, min(0.95, $player->damageReductionRate / 100)));
        $armorResistanceRate = NationRaidRules::ARMOR_SPECIES_RESISTANCE_ENABLED
            ? $this->pveArmorResistanceRate(
                $boss,
                $player,
                NationRaidRules::ARMOR_SPECIES_RESISTANCE_RATE_CAP,
            )
            : 0.0;
        $finalMultiplier = $legacyMultiplier * (1 - $armorResistanceRate);

        return new NationRaidPlayerTurnState(
            maxHp: $player->maxHp,
            currentHp: $player->hp,
            defense: $player->effectiveDef(),
            spirit: $player->effectiveSpr(),
            maxSp: $player->maxMp,
            currentSp: $player->mp,
            enemyHitChancePercent: max(82.0, min(99.0, $hitChance)),
            enemyEvadeChancePercent: max(0.0, min(100.0, $activeEvasion)),
            enemyCriticalChancePercent: $this->damageCalculator->criticalChance($boss, $player),
            finalDamageReductionRate: max(0.0, min(0.95, 1 - $finalMultiplier)),
            incomingDamageApplier: new NationRaidExistingPlayerDefenseApplier(
                player: $player,
                boss: $boss,
                state: $state,
                damageApplication: $this->damageApplicationService,
                resourceService: $this->jobArtV2ResourceService,
                ultimateCounterplayService: $this->jobArtV2UltimateCounterplayService,
            ),
        );
    }

    private function synchronizePlayerAfterBossTurn(
        BattleActor $player,
        BattleActor $boss,
        BattleState $state,
        NationRaidBossTurnResolution $resolution,
    ): void {
        if ($resolution->playerHp < $player->hp) {
            $player->takeDamage($player->hp - $resolution->playerHp);
        } elseif ($resolution->playerHp > $player->hp) {
            $player->healHp($resolution->playerHp - $player->hp);
        }
        // Phase 1の非致死・撃破判定を正本にする。既存防御pipeline適用済みなら値は一致する。
        $player->hp = $resolution->playerHp;
        $player->mp = $resolution->playerSp;

        for ($i = 0; $i < $resolution->evadedHitCount(); $i++) {
            $this->jobArtV2ProgressionService->consumeHuntingMarksFor($boss, $player, 1);
        }
        $breakConsumed = (int) ($resolution->turnRecord['counterplay']['breakMarksConsumed'] ?? 0);
        if ($breakConsumed > 0) {
            $this->jobArtV2ProgressionService->consumeBreakMarksFor($boss, $player, $breakConsumed);
        }

        $this->synchronizeHealingReduction($player, $resolution->healingReductionRate, $resolution->healingReductionTurns);
        foreach ($resolution->appliedEnemyEffects as $effect) {
            if ($effect === 'field_remove_and_extension_block') {
                $this->jobArtV2FieldService->clearAll($state);
            }
            if ($effect === 'clear_marks_and_next_multihit_down_25') {
                $progression = $boss->jobArtV2ProgressionState();
                $progression->huntingMarks = [];
                $progression->breakMarks = [];
                $progression->crownBreakMarks = [];
                $progression->breakMarkOwners = [];
            }
            if ($effect === 'cleanse_and_guard_per_debuff') {
                $this->clearBossDebuffs($boss);
            }
        }
    }

    private function synchronizeHealingReduction(BattleActor $player, float $rate, int $turns): void
    {
        $current = $player->conditions['recovery_block'] ?? null;
        if ($rate <= 0 || $turns <= 0) {
            if (is_array($current) && ($current['raid_bridge'] ?? false) === true) {
                unset($player->conditions['recovery_block']);
            }

            return;
        }

        $player->conditions['recovery_block'] = [
            'turns' => max($turns, (int) ($current['turns'] ?? 0)),
            'rate' => max($rate, (float) ($current['rate'] ?? 0.0)),
            'raid_bridge' => true,
        ];
    }

    private function clearBossDebuffs(BattleActor $boss): void
    {
        foreach ($boss->conditions as $key => $condition) {
            if (is_array($condition) && (float) ($condition['rate'] ?? 0.0) > 0.0) {
                unset($boss->conditions[$key]);
            }
        }
        foreach ($boss->jobArtV2TimedEffects() as $effect) {
            if (array_filter($effect->statModifiers, static fn (mixed $rate): bool => (float) $rate < 0.0) !== []) {
                $boss->removeJobArtV2TimedEffect($effect->key);
            }
        }
        $boss->replaceBreakDebuffState(null);
    }

    /** @return list<string> */
    private function debuffStateKeys(BattleActor $actor): array
    {
        $keys = [];
        foreach ($actor->conditions as $key => $value) {
            if ($value !== null && $value !== false && $value !== 0) {
                $keys[] = 'condition:'.(string) $key;
            }
        }
        foreach ($actor->jobArtV2TimedEffects() as $effect) {
            if (! $effect->isExpired() && array_filter(
                $effect->statModifiers,
                static fn (mixed $rate): bool => (float) $rate < 0,
            ) !== []) {
                $keys[] = 'timed:'.$effect->key;
            }
        }
        if ($actor->breakDebuffState() !== null) {
            $keys[] = 'break_debuff';
        }

        sort($keys);

        return array_values(array_unique($keys));
    }

    private function raidDamageKind(DamageSourceType $sourceType): string
    {
        return match ($sourceType) {
            DamageSourceType::DOT => NationRaidRules::DAMAGE_DOT,
            DamageSourceType::COUNTER => NationRaidRules::DAMAGE_COUNTER,
            DamageSourceType::NORMAL_ATTACK, DamageSourceType::JOB_SKILL, DamageSourceType::JOB_ART => NationRaidRules::DAMAGE_DIRECT,
            default => NationRaidRules::DAMAGE_SIMULTANEOUS,
        };
    }

    private function defenseIgnoreDamage(
        int $damage,
        BattleActor $source,
        BattleActor $target,
        ?string $damageCategory,
        string $kind,
    ): int {
        if (! in_array($kind, [NationRaidRules::DAMAGE_DIRECT, NationRaidRules::DAMAGE_SIMULTANEOUS], true)
            || ! in_array($damageCategory, ['physical', 'magical'], true)
        ) {
            return $damage;
        }

        $attack = $damageCategory === 'magical' ? $source->effectiveMag() : $source->effectiveStr();
        $defense = $damageCategory === 'magical' ? $target->effectiveSpr() : $target->effectiveDef();
        $normalDenominator = $attack + (NationRaidRules::DEFENSE_COEFFICIENT * $defense);
        $ignoredDenominator = $attack + (NationRaidRules::DEFENSE_COEFFICIENT * $defense * 0.50);
        if ($normalDenominator <= 0 || $ignoredDenominator <= 0) {
            return $damage;
        }

        return max($damage, (int) floor($damage * $normalDenominator / $ignoredDenominator));
    }

    /** @return array<string, mixed> */
    private function actionArray(NationRaidPlayerActionSnapshot $action): array
    {
        return [
            'turn' => $action->turn,
            'damage_sources' => $action->damageSources,
            'selected_counterplay_identity' => $action->selectedCounterplayIdentity,
            'boss_debuff_keys_applied' => $action->bossDebuffKeysApplied,
            'counterplay_hit' => $action->counterplayHit,
            'hunting_mark_count' => $action->huntingMarkCount,
            'break_mark_count' => $action->breakMarkCount,
        ];
    }

    private function raidBoss(): Enemy
    {
        $enemy = new Enemy([
            'name' => '十系喰らいの黒天竜 ヴァルグレイド',
            'species_key' => NationRaidRules::BOSS_SPECIES_KEY,
            'level' => 1,
            'max_hp' => 2_000_000_000,
            'max_mp' => NationRaidRules::BOSS_MAX_SP,
            'str' => NationRaidRules::BOSS_AGILITY,
            'def' => NationRaidRules::BOSS_DEFENSE,
            'agi' => NationRaidRules::BOSS_AGILITY,
            'mag' => NationRaidRules::BOSS_AGILITY,
            'spr' => NationRaidRules::BOSS_SPIRIT,
            'luk' => NationRaidRules::BOSS_LUCK,
            'is_boss' => true,
            'normal_attack_type' => 'physical',
            'skip_danger_bonus' => true,
            'skip_durability_bonus' => true,
            'exp_reward' => 0,
            'gold_reward' => 0,
            'job_exp_reward' => 0,
        ]);
        $enemy->setRelation('actions', collect());
        $enemy->setRelation('area', null);

        return $enemy;
    }
}
