<?php

namespace App\Services\Nation\Raid\Simulation;

use App\Services\Nation\Raid\NationRaidBattleEngine;
use App\Services\Nation\Raid\NationRaidBattleInput;
use App\Services\Nation\Raid\NationRaidJson;
use App\Services\Nation\Raid\NationRaidPlayerActionSnapshot;
use App\Services\Nation\Raid\NationRaidPlayerSnapshot;
use App\Services\Nation\Raid\NationRaidRules;
use App\Services\Nation\Raid\NationRaidLineageVoteResolver;
use DomainException;
use InvalidArgumentException;

/** 匿名snapshotだけを入力にし、DBへ触れず7日間の共有HP進行を再生する。 */
final class NationRaidSimulationRunner
{
    private const PLAYER_SNAPSHOT_MODEL_VERSION = 'current-pve-rates-reference-v1';

    private const PLAYER_SNAPSHOT_MODEL_AUTHORITATIVE = false;

    // 旧5回/日・35回/週の参考モデル。回数無制限の実参加量はまだ較正していない。
    private const PARTICIPATION_MODEL_AUTHORITATIVE = false;

    private const PVE_HIT_BASE = 90.0;

    private const PVE_HIT_AGILITY_FACTOR = 0.5;

    private const PVE_HIT_MIN = 70.0;

    private const PVE_HIT_MAX = 98.0;

    private const PVE_CRITICAL_BASE = 5.0;

    private const PVE_CRITICAL_LUCK_FACTOR = 0.2;

    private const PVE_CRITICAL_MIN = 1.0;

    private const PVE_CRITICAL_MAX = 30.0;

    public function __construct(
        private readonly NationRaidSimulationSnapshotValidator $validator,
        private readonly NationRaidBattleEngine $engine,
        private readonly NationRaidRules $rules,
        private readonly NationRaidKillerPopulationSummary $killerPopulationSummary,
        private readonly NationRaidResolvedContextPlan $resolvedContextPlan,
        private readonly NationRaidCoordinationTimingModel $coordinationTiming,
        private readonly NationRaidLineageVoteResolver $lineageVotes,
    ) {}

    /** review済みcontext cacheと実測時刻modelが揃った入力だけをbalance判定候補にする。 */
    public function authoritativeForBalanceGate(array $snapshot): bool
    {
        return self::PARTICIPATION_MODEL_AUTHORITATIVE
            && $this->resolvedContextProfilesAvailable($snapshot)
            && ($snapshot['coordination_timing_model_hash'] ?? null) === $this->coordinationTiming->contractHash()
            && ($snapshot['coordination_timing_model'] ?? null) === $this->coordinationTiming->contract();
    }

    /** review済みcontext cacheを参考simulationへ利用できるかを判定する。 */
    private function resolvedContextProfilesAvailable(array $snapshot): bool
    {
        return ($snapshot['resolved_context_profile_authoritative'] ?? false) === true
            && ($snapshot['resolved_context_plan_schema'] ?? null) === NationRaidResolvedContextPlan::SCHEMA_VERSION
            && ($snapshot['resolved_context_plan_coverage_complete'] ?? false) === true
            && ($snapshot['resolved_context_profile_model'] ?? null) === NationRaidResolvedProfileProjector::MODEL_VERSION
            && ($snapshot['resolved_context_contract_hash'] ?? null) === NationRaidResolvedProfileContext::contractHash();
    }

    /**
     * @param  list<float>  $participationRates
     * @return array<string, mixed>
     */
    public function run(
        array $snapshot,
        int $seeds = 1_000,
        int $seedStart = 1,
        array $participationRates = [0.40, 0.60, 0.80, 1.00],
        string $strategyMode = NationRaidRules::STRATEGY_BOSS_SET,
        bool $allowReferenceProfile = false,
    ): array {
        $validation = $this->validator->validate($snapshot);
        if (! $validation['ready']) {
            throw new DomainException('Raid simulation snapshot validation failed.');
        }
        if (! $this->authoritativeForBalanceGate($snapshot) && ! $allowReferenceProfile) {
            throw new DomainException('Reference-only simulation inputs require explicit opt-in.');
        }
        if ($seeds < 1 || $seeds > 100_000) {
            throw new InvalidArgumentException('Simulation seed count must be between 1 and 100000.');
        }
        if (! in_array($strategyMode, ['mixed_equal', ...$this->rules->strategyKeys()], true)) {
            throw new InvalidArgumentException('Unknown simulation strategy mode.');
        }

        $characters = array_values($snapshot['characters']);
        $useResolvedProfiles = $this->resolvedContextProfilesAvailable($snapshot);
        $playerSnapshots = $useResolvedProfiles ? [] : $this->playerSnapshots($characters);
        $resolvedProfiles = $useResolvedProfiles ? $this->resolvedProfileIndex($characters) : [];
        $resolvedProfilesPerContext = $useResolvedProfiles
            ? (int) $snapshot['resolved_context_profiles_per_context']
            : 0;
        $snapshotHash = hash('sha256', NationRaidJson::encode($snapshot, JSON_UNESCAPED_UNICODE));
        $scenarios = [];
        $contextMetrics = new NationRaidResolvedContextMetricsCollector(
            snapshot: $snapshot,
            contextPlan: $this->resolvedContextPlan,
            authoritativeCache: $useResolvedProfiles,
        );
        $contextMetrics->startRuntime();

        foreach ($participationRates as $rate) {
            $rate = (float) $rate;
            if ($rate <= 0 || $rate > 1) {
                throw new InvalidArgumentException('Participation rates must be within (0, 1].');
            }
            $scenarios[] = $this->runScenarioSet(
                characters: $characters,
                playerSnapshots: $playerSnapshots,
                resolvedProfiles: $resolvedProfiles,
                resolvedProfilesPerContext: $resolvedProfilesPerContext,
                useResolvedProfiles: $useResolvedProfiles,
                contextMetrics: $contextMetrics,
                seeds: $seeds,
                seedStart: $seedStart,
                participationRate: $rate,
                strategyMode: $strategyMode,
                exclusionMode: 'none',
            );
        }

        // 12-1の上位10%除外gate。分母解釈の感度も同じsnapshot/seedで併記する。
        foreach (['remaining_pool', 'original_population'] as $denominator) {
            $scenarios[] = $this->runScenarioSet(
                characters: $characters,
                playerSnapshots: $playerSnapshots,
                resolvedProfiles: $resolvedProfiles,
                resolvedProfilesPerContext: $resolvedProfilesPerContext,
                useResolvedProfiles: $useResolvedProfiles,
                contextMetrics: $contextMetrics,
                seeds: $seeds,
                seedStart: $seedStart,
                participationRate: 0.80,
                strategyMode: $strategyMode,
                exclusionMode: 'top_10_percent_damage',
                exclusionDenominator: $denominator,
            );
        }

        return [
            'artifact_version' => 'nation-raid-phase2-simulation-v10',
            'generated_at' => now()->toIso8601String(),
            'ruleset_hash' => $snapshot['ruleset_hash'],
            'boss_species_key' => $snapshot['boss_species_key'],
            'raid_killer_contract_hash' => $snapshot['raid_killer_contract_hash'],
            'coordination_timing_model_hash' => $snapshot['coordination_timing_model_hash'],
            'integration_hash' => $snapshot['integration_hash'],
            'action_profile_cache_hash' => $snapshot['action_profile_cache_hash'],
            'resolved_context_profile_model' => $snapshot['resolved_context_profile_model'],
            'resolved_context_profile_cache_hash' => $snapshot['resolved_context_profile_cache_hash'],
            'resolved_context_contract_hash' => $snapshot['resolved_context_contract_hash'],
            'resolved_context_plan_schema' => $snapshot['resolved_context_plan_schema'],
            'resolved_context_plan_coverage_complete' => $snapshot['resolved_context_plan_coverage_complete'],
            'resolved_context_plan_hash' => $snapshot['resolved_context_plan_hash'],
            'snapshot_hash' => $snapshotHash,
            'snapshot_extracted_at' => $snapshot['extracted_at'],
            'seed_start' => $seedStart,
            'seed_count' => $seeds,
            'strategy_mode' => $strategyMode,
            'participation_model' => [
                'authoritative_for_balance_gate' => self::PARTICIPATION_MODEL_AUTHORITATIVE,
                'limitation' => 'legacy_fixed_5_per_day_reference_not_unlimited_sorties',
                'version' => 'weighted-fixed-slot-selection-v1',
                'denominator' => 'active_character_count_x_35',
                'cluster' => 'same_character_max_5_per_day_and_35_per_event',
                'weight' => 'sqrt(max(1,battles_7d))_normalized_to_population_median_and_clamped_0.25_to_4.0',
            ],
            'player_snapshot_model' => $useResolvedProfiles
                ? $this->resolvedProfileModel()
                : $this->playerSnapshotModel(),
            'coordination_model' => $this->coordinationModel(),
            'lineage_voting_model' => [...$this->lineageVotes->contract(), 'hash' => $this->lineageVotes->contractHash()],
            'action_profile_model' => $snapshot['action_profile_model'],
            'balance_gate_authoritative' => $this->authoritativeForBalanceGate($snapshot),
            'reference_model_opt_in' => $allowReferenceProfile,
            'reference_profile_opt_in' => $allowReferenceProfile,
            'raid_killer_population' => $this->killerPopulationSummary->summarize(
                $characters,
                (int) ($snapshot['population_report']['raid_killer_unavailable_characters'] ?? 0),
            ),
            'validation' => $validation,
            'resolved_context_cache_metrics' => $contextMetrics->report(
                (bool) $snapshot['resolved_context_plan_coverage_complete'],
            ),
            'scenarios' => $scenarios,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $characters
     * @param  array<string, array<int, NationRaidPlayerSnapshot>>  $playerSnapshots
     * @param  array<string, array<string, array<string, mixed>>>  $resolvedProfiles
     * @return array<string, mixed>
     */
    private function runScenarioSet(
        array $characters,
        array $playerSnapshots,
        array $resolvedProfiles,
        int $resolvedProfilesPerContext,
        bool $useResolvedProfiles,
        NationRaidResolvedContextMetricsCollector $contextMetrics,
        int $seeds,
        int $seedStart,
        float $participationRate,
        string $strategyMode,
        string $exclusionMode,
        string $exclusionDenominator = 'remaining_pool',
    ): array {
        $eligible = $characters;
        $excluded = [];
        if ($exclusionMode === 'top_10_percent_damage') {
            [$eligible, $excluded] = $this->excludeTopDamageCharacters($characters, $useResolvedProfiles);
        }

        $summaries = [];
        for ($offset = 0; $offset < $seeds; $offset++) {
            $seed = $seedStart + $offset;
            $summaries[] = $this->runEvent(
                allCharacterCount: count($characters),
                characters: $eligible,
                playerSnapshots: $playerSnapshots,
                resolvedProfiles: $resolvedProfiles,
                resolvedProfilesPerContext: $resolvedProfilesPerContext,
                useResolvedProfiles: $useResolvedProfiles,
                contextMetrics: $contextMetrics,
                participationRate: $participationRate,
                seed: $seed,
                strategyMode: $strategyMode,
                denominator: $exclusionDenominator,
            );
        }

        $completion = array_column($summaries, 'completed');
        $stage10 = array_column($summaries, 'stage10_reached');
        $finalStages = array_column($summaries, 'final_stage_reached');
        $echoes = array_column($summaries, 'echoes_defeated');
        $completionDays = array_values(array_filter(
            array_column($summaries, 'completion_day'),
            static fn (mixed $day): bool => is_int($day),
        ));
        $stage10Days = array_values(array_filter(
            array_column($summaries, 'stage10_day'),
            static fn (mixed $day): bool => is_int($day),
        ));

        return [
            'scenario_key' => $this->scenarioKey($participationRate, $exclusionMode, $exclusionDenominator),
            'participation_rate' => $participationRate,
            'exclusion_mode' => $exclusionMode,
            'exclusion_denominator' => $exclusionDenominator,
            'eligible_characters' => count($eligible),
            'excluded_characters' => count($excluded),
            'resolved_slots_per_run' => $summaries[0]['resolved_slots'] ?? 0,
            'completion_probability' => $this->meanBooleans($completion),
            'stage10_reach_probability' => $this->meanBooleans($stage10),
            'final_stage_reached' => $this->distribution($finalStages),
            'completion_day' => $this->distribution($completionDays),
            'stage10_day' => $this->distribution($stage10Days),
            'echoes_defeated' => $this->distribution($echoes),
            'mean_total_damage' => $this->mean(array_column($summaries, 'total_damage')),
            'mean_personal_damage' => $this->mean(array_column($summaries, 'personal_damage')),
            'mean_coordination_damage' => $this->mean(array_column($summaries, 'coordination_damage')),
            'coordination_metrics' => $this->sumCoordinationMetrics(array_column($summaries, 'coordination_metrics')),
            'battle_metrics' => $this->sumBattleMetrics(array_column($summaries, 'battle_metrics')),
            'reward_metrics' => $this->meanRewardMetrics(array_column($summaries, 'reward_metrics')),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $characters
     * @param  array<string, array<int, NationRaidPlayerSnapshot>>  $playerSnapshots
     * @param  array<string, array<string, array<string, mixed>>>  $resolvedProfiles
     * @return array<string, mixed>
     */
    private function runEvent(
        int $allCharacterCount,
        array $characters,
        array $playerSnapshots,
        array $resolvedProfiles,
        int $resolvedProfilesPerContext,
        bool $useResolvedProfiles,
        NationRaidResolvedContextMetricsCollector $contextMetrics,
        float $participationRate,
        int $seed,
        string $strategyMode,
        string $denominator,
    ): array {
        $slots = $this->selectedSlots($allCharacterCount, $characters, $participationRate, $seed, $denominator);
        $charactersByKey = [];
        foreach ($characters as $row) {
            $charactersByKey[$row['character_key']] = $row;
        }

        $stage = 1;
        $cycleNo = 1;
        $cycleMaxHp = $this->rules->stageMaxHp($stage);
        $cycleHp = $cycleMaxHp;
        $mainCompleted = false;
        $echoesDefeated = 0;
        $totalDamage = 0;
        $personalDamageTotal = 0;
        $coordinationDamageTotal = 0;
        $stage10Day = null;
        $completionDay = null;
        $dominantLineage = null;
        $personal = [];
        $battleMetrics = $this->emptyBattleMetrics();
        $coordinationMetrics = $this->emptyCoordinationMetrics();
        $activeCoordinationByNation = [];

        foreach (range(1, 7) as $day) {
            $daySlots = array_values(array_filter($slots, static fn (array $slot): bool => $slot['day'] === $day));
            usort($daySlots, function (array $a, array $b) use ($seed): int {
                $minuteOrder = $a['event_minute'] <=> $b['event_minute'];

                return $minuteOrder !== 0
                    ? $minuteOrder
                    : $this->scoreHex($seed, 'time-tie|'.$a['slot_key']) <=> $this->scoreHex($seed, 'time-tie|'.$b['slot_key']);
            });
            $firstSortieSeen = [];
            $votes = [];

            foreach ($daySlots as $slot) {
                $row = $charactersByKey[$slot['character_key']];
                $key = $row['character_key'];
                if (! isset($firstSortieSeen[$key])) {
                    foreach ($row['lineage_votes'] as $lineage) {
                        $votes[$lineage] = (int) ($votes[$lineage] ?? 0) + 1;
                    }
                    $firstSortieSeen[$key] = true;
                }

                $profileCount = $useResolvedProfiles
                    ? $resolvedProfilesPerContext
                    : count($playerSnapshots[$key]);
                $profileNo = 1 + ($this->derivedSeed($seed, 'profile|'.$slot['slot_key']) % $profileCount);
                $strategy = $this->strategyFor($strategyMode, $seed, $slot['slot_key']);
                $form = $this->rules->formForHp($cycleHp, $cycleMaxHp);
                $context = NationRaidResolvedProfileContext::forProfile(
                    characterKey: $key,
                    stage: $stage,
                    startingForm: $form,
                    strategy: $strategy,
                    dominantLineage: $dominantLineage,
                    profileNo: $profileNo,
                );
                if ($useResolvedProfiles) {
                    $cached = $resolvedProfiles[$key][$context->key()] ?? null;
                    $cacheHit = is_array($cached) && is_array($cached['result'] ?? null);
                    $contextMetrics->record($context, $cacheHit);
                    if (! $cacheHit) {
                        throw new DomainException('Authoritative raid context cache is missing: '.$context->baseKey());
                    }
                    $result = $cached['result'];
                    $damage = (int) $result['calculated_boss_damage'];
                } else {
                    $contextMetrics->record($context, null);
                    $profiles = $playerSnapshots[$key];
                    $result = $this->engine->resolve(new NationRaidBattleInput(
                        stage: $stage,
                        cycleCurrentHp: $cycleHp,
                        cycleMaxHp: $cycleMaxHp,
                        sourceCycleId: "simulation:{$seed}:{$cycleNo}",
                        dominantLineage: $dominantLineage,
                        seed: $this->derivedSeed($seed, 'battle|'.$slot['slot_key'].'|'.$cycleNo),
                        strategy: $strategy,
                        player: $profiles[$profileNo],
                    ));
                    $damage = $result->calculatedBossDamage;
                }
                $personalDamage = $damage;
                $coordination = $this->coordinationTiming->register(
                    activeByNation: $activeCoordinationByNation,
                    nationKey: is_string($row['nation_key'] ?? null) ? $row['nation_key'] : null,
                    characterKey: $key,
                    eventMinute: (int) $slot['event_minute'],
                );
                $coordinationDamage = $this->coordinationTiming->coordinationDamage(
                    $personalDamage,
                    $coordination['bonus_rate'],
                );
                $damage = $personalDamage + $coordinationDamage;
                $personalDamageTotal += $personalDamage;
                $coordinationDamageTotal += $coordinationDamage;
                $totalDamage += $damage;
                $this->accumulateCoordinationMetrics(
                    $coordinationMetrics,
                    $coordination,
                    $personalDamage,
                    $coordinationDamage,
                );
                $personal[$key]['sorties'] = (int) ($personal[$key]['sorties'] ?? 0) + 1;
                $personal[$key]['damage'] = (int) ($personal[$key]['damage'] ?? 0) + $personalDamage;
                if ($useResolvedProfiles) {
                    $this->accumulateResolvedBattleMetrics($battleMetrics, $result, $stage, $key);
                } else {
                    $this->accumulateBattleMetrics($battleMetrics, $result, $stage, $key);
                }

                while ($damage > 0) {
                    $applied = min($damage, $cycleHp);
                    $cycleHp -= $applied;
                    $damage -= $applied;
                    if ($cycleHp > 0) {
                        break;
                    }

                    $cycleNo++;
                    $cycleMaxHp = $this->rules->stageMaxHp(min($stage + 1, NationRaidRules::MAX_STAGES));
                    $cycleHp = $cycleMaxHp;
                    if (! $mainCompleted && $stage < NationRaidRules::MAX_STAGES) {
                        $stage++;
                        if ($stage === 10 && $stage10Day === null) {
                            $stage10Day = $day;
                        }

                        continue;
                    }
                    if (! $mainCompleted) {
                        $mainCompleted = true;
                        $completionDay = $day;
                        $stage = NationRaidRules::MAX_STAGES;
                    } else {
                        $echoesDefeated++;
                    }
                }
            }

            $dominantLineage = $this->dominantLineage($votes, $seed);
        }

        return [
            'completed' => $mainCompleted,
            'stage10_reached' => $stage >= 10 || $mainCompleted,
            'final_stage_reached' => $stage,
            'stage10_day' => $stage10Day,
            'completion_day' => $completionDay,
            'echoes_defeated' => $echoesDefeated,
            'resolved_slots' => count($slots),
            'total_damage' => $totalDamage,
            'personal_damage' => $personalDamageTotal,
            'coordination_damage' => $coordinationDamageTotal,
            'coordination_metrics' => $this->finalizeCoordinationMetrics($coordinationMetrics),
            'battle_metrics' => $this->finalizeBattleMetrics($battleMetrics),
            'reward_metrics' => $this->rewardMetrics($personal, $stage >= 10 || $mainCompleted, $mainCompleted),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $characters
     * @return list<array{character_key:string,day:int,slot:int,slot_key:string,event_minute:int}>
     */
    private function selectedSlots(int $allCharacterCount, array $characters, float $rate, int $seed, string $denominator): array
    {
        $battleCounts = array_map(static fn (array $row): int => max(1, (int) $row['activity']['battles_7d']), $characters);
        sort($battleCounts);
        $median = max(1.0, (float) $this->quantile($battleCounts, 0.50));
        $candidates = [];
        foreach ($characters as $row) {
            $weight = max(0.25, min(4.0, sqrt(max(1, (int) $row['activity']['battles_7d']) / $median)));
            foreach (range(1, 7) as $day) {
                foreach (range(1, 5) as $slot) {
                    $slotKey = $row['character_key']."|{$day}|{$slot}";
                    $uniform = $this->uniform($seed, 'participation|'.$slotKey);
                    $candidates[] = [
                        'character_key' => $row['character_key'],
                        'day' => $day,
                        'slot' => $slot,
                        'slot_key' => $slotKey,
                        'event_minute' => $this->coordinationTiming->eventMinute(
                            activity: $row['activity'],
                            day: $day,
                            slot: $slot,
                            seed: $seed,
                            characterKey: $row['character_key'],
                        ),
                        'priority' => -log($uniform) / $weight,
                    ];
                }
            }
        }

        $denominatorCharacters = $denominator === 'original_population' ? $allCharacterCount : count($characters);
        $target = min(count($candidates), (int) round($denominatorCharacters * 35 * $rate));
        usort($candidates, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        return array_map(
            static fn (array $slot): array => array_diff_key($slot, ['priority' => true]),
            array_slice($candidates, 0, $target),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $characters
     * @return array{0:list<array<string, mixed>>,1:list<array<string, mixed>>}
     */
    private function excludeTopDamageCharacters(array $characters, bool $useResolvedProfiles): array
    {
        usort($characters, fn (array $a, array $b): int => $this->referenceDamage($b, $useResolvedProfiles) <=> $this->referenceDamage($a, $useResolvedProfiles)
        );
        $count = max(1, (int) ceil(count($characters) * 0.10));

        return [array_slice($characters, $count), array_slice($characters, 0, $count)];
    }

    /** @param array<string, mixed> $row */
    private function referenceDamage(array $row, bool $useResolvedProfiles): int
    {
        if ($useResolvedProfiles) {
            $totals = array_map(
                static fn (array $profile): int => (int) ($profile['result']['calculated_boss_damage'] ?? 0),
                is_array($row['resolved_context_profiles'] ?? null) ? $row['resolved_context_profiles'] : [],
            );

            return (int) round($this->mean($totals));
        }

        $totals = [];
        foreach ($row['action_profiles'] as $profile) {
            $sum = 0;
            foreach ($profile['actions'] as $action) {
                foreach ($action['damage_sources'] as $source) {
                    $sum += (int) $source['damage'];
                }
            }
            $totals[] = $sum;
        }

        return (int) round($this->mean($totals));
    }

    /**
     * @param  list<array<string, mixed>>  $characters
     * @return array<string, array<int, NationRaidPlayerSnapshot>>
     */
    private function playerSnapshots(array $characters): array
    {
        $result = [];
        foreach ($characters as $row) {
            $abilities = $row['abilities'];
            foreach ($row['action_profiles'] as $profile) {
                $actions = [];
                foreach ($profile['actions'] as $action) {
                    $actions[] = new NationRaidPlayerActionSnapshot(
                        turn: $action['turn'],
                        damageSources: $action['damage_sources'],
                        selectedCounterplayIdentity: $action['selected_counterplay_identity'],
                        bossDebuffKeysApplied: $action['boss_debuff_keys_applied'],
                        counterplayHit: $action['counterplay_hit'],
                        huntingMarkCount: $action['hunting_mark_count'],
                        breakMarkCount: $action['break_mark_count'],
                    );
                }
                $result[$row['character_key']][(int) $profile['profile_no']] = new NationRaidPlayerSnapshot(
                    maxHp: $abilities['max_hp'],
                    defense: $abilities['defense'],
                    spirit: $abilities['spirit'],
                    maxSp: $abilities['max_sp'],
                    enemyHitChancePercent: max(
                        self::PVE_HIT_MIN,
                        min(
                            self::PVE_HIT_MAX,
                            self::PVE_HIT_BASE + ((NationRaidRules::BOSS_AGILITY - $abilities['agility']) * self::PVE_HIT_AGILITY_FACTOR),
                        ),
                    ),
                    enemyEvadeChancePercent: 0.0,
                    enemyCriticalChancePercent: max(
                        self::PVE_CRITICAL_MIN,
                        min(
                            self::PVE_CRITICAL_MAX,
                            self::PVE_CRITICAL_BASE + ((NationRaidRules::BOSS_LUCK - $abilities['luck']) * self::PVE_CRITICAL_LUCK_FACTOR),
                        ),
                    ),
                    finalDamageReductionRate: (float) $row['raid_resistance']['damage_reduction_rate'],
                    counterplayEnabled: $row['job']['counterplay_enabled'],
                    bossSetExactIdentities: $row['boss_set_exact_identities'],
                    actions: $actions,
                );
            }
            ksort($result[$row['character_key']]);
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $characters
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function resolvedProfileIndex(array $characters): array
    {
        $index = [];
        foreach ($characters as $row) {
            $characterKey = (string) $row['character_key'];
            foreach ($row['resolved_context_profiles'] as $profile) {
                $index[$characterKey][(string) $profile['context_key']] = $profile;
            }
        }

        return $index;
    }

    /** @param array<string, int> $votes */
    private function dominantLineage(array $votes, int $seed): ?string
    {
        return $this->lineageVotes->resolve($votes, hash('sha256', "nation-raid-event|{$seed}"))['selected'];
    }

    private function strategyFor(string $mode, int $seed, string $slotKey): string
    {
        if ($mode !== 'mixed_equal') {
            return $mode;
        }
        // mixed_equalは従来3作戦の比較用。通常の既定実行はboss_setのみ。
        $strategies = $this->rules->selectableStrategyKeys();

        return $strategies[$this->derivedSeed($seed, 'strategy|'.$slotKey) % count($strategies)];
    }

    /** @return array<string, mixed> */
    private function emptyCoordinationMetrics(): array
    {
        return [
            'sorties' => 0,
            'eligible_sorties' => 0,
            'unaffiliated_sorties' => 0,
            'bonus_sorties' => 0,
            'newly_registered_sorties' => 0,
            'personal_damage_eligible' => 0,
            'coordination_damage' => 0,
            'shared_hp_damage_eligible' => 0,
            'rate_basis_points_total' => 0,
            'max_unique_participants' => 0,
            'unique_count_distribution' => [],
            'bonus_rate_distribution' => [],
        ];
    }

    /**
     * @param  array{eligible:bool,unique_count:int,bonus_rate:float,newly_registered:bool}  $coordination
     */
    private function accumulateCoordinationMetrics(
        array &$metrics,
        array $coordination,
        int $personalDamage,
        int $coordinationDamage,
    ): void {
        $metrics['sorties']++;
        if (! $coordination['eligible']) {
            $metrics['unaffiliated_sorties']++;

            return;
        }

        $uniqueCount = $coordination['unique_count'];
        $rateBasisPoints = (int) round($coordination['bonus_rate'] * 10_000);
        $uniqueBucket = $uniqueCount >= 5 ? '5+' : (string) $uniqueCount;
        $rateBucket = (string) $rateBasisPoints;
        $metrics['eligible_sorties']++;
        $metrics['bonus_sorties'] += $rateBasisPoints > 0 ? 1 : 0;
        $metrics['newly_registered_sorties'] += $coordination['newly_registered'] ? 1 : 0;
        $metrics['personal_damage_eligible'] += $personalDamage;
        $metrics['coordination_damage'] += $coordinationDamage;
        $metrics['shared_hp_damage_eligible'] += $personalDamage + $coordinationDamage;
        $metrics['rate_basis_points_total'] += $rateBasisPoints;
        $metrics['max_unique_participants'] = max($metrics['max_unique_participants'], $uniqueCount);
        $metrics['unique_count_distribution'][$uniqueBucket] = (int) ($metrics['unique_count_distribution'][$uniqueBucket] ?? 0) + 1;
        $metrics['bonus_rate_distribution'][$rateBucket] = (int) ($metrics['bonus_rate_distribution'][$rateBucket] ?? 0) + 1;
    }

    /** @param array<string, mixed> $metrics @return array<string, mixed> */
    private function finalizeCoordinationMetrics(array $metrics): array
    {
        ksort($metrics['unique_count_distribution'], SORT_NATURAL);
        ksort($metrics['bonus_rate_distribution'], SORT_NUMERIC);
        $eligible = $metrics['eligible_sorties'];
        $sharedDamage = $metrics['shared_hp_damage_eligible'];
        $metrics['bonus_sortie_rate'] = $eligible > 0 ? $metrics['bonus_sorties'] / $eligible : 0.0;
        $metrics['mean_bonus_rate'] = $eligible > 0
            ? ($metrics['rate_basis_points_total'] / 10_000) / $eligible
            : 0.0;
        $metrics['coordination_share_of_eligible_shared_damage'] = $sharedDamage > 0
            ? $metrics['coordination_damage'] / $sharedDamage
            : 0.0;

        return $metrics;
    }

    /** @param list<array<string, mixed>> $metrics */
    private function sumCoordinationMetrics(array $metrics): array
    {
        $sum = $this->emptyCoordinationMetrics();
        foreach ($metrics as $row) {
            foreach ([
                'sorties',
                'eligible_sorties',
                'unaffiliated_sorties',
                'bonus_sorties',
                'newly_registered_sorties',
                'personal_damage_eligible',
                'coordination_damage',
                'shared_hp_damage_eligible',
                'rate_basis_points_total',
            ] as $key) {
                $sum[$key] += (int) ($row[$key] ?? 0);
            }
            $sum['max_unique_participants'] = max(
                $sum['max_unique_participants'],
                (int) ($row['max_unique_participants'] ?? 0),
            );
            foreach (($row['unique_count_distribution'] ?? []) as $bucket => $count) {
                $sum['unique_count_distribution'][(string) $bucket] = (int) ($sum['unique_count_distribution'][(string) $bucket] ?? 0) + (int) $count;
            }
            foreach (($row['bonus_rate_distribution'] ?? []) as $bucket => $count) {
                $sum['bonus_rate_distribution'][(string) $bucket] = (int) ($sum['bonus_rate_distribution'][(string) $bucket] ?? 0) + (int) $count;
            }
        }

        return $this->finalizeCoordinationMetrics($sum);
    }

    /** @return array<string, mixed> */
    private function emptyBattleMetrics(): array
    {
        return [
            'sorties' => 0,
            'turns_completed_total' => 0,
            't20_reached' => 0,
            'ultimate_executed' => 0,
            'ultimate_denial_reasons' => [],
            'reservation_failures' => 0,
            'observations' => 0,
            'cap_binding_hits' => 0,
            'enemy_damage_actions' => 0,
            'counterplay_applied' => 0,
            'guard_consumed_actions' => 0,
            'parry_succeeded_actions' => 0,
            'guts_triggered_actions' => 0,
            'actual_hp_loss' => 0,
            'counter_damage' => 0,
            'forms_by_character' => [],
            'stage_sorties' => array_fill(1, NationRaidRules::MAX_STAGES, 0),
        ];
    }

    private function accumulateBattleMetrics(array &$metrics, object $result, int $stage, string $characterKey): void
    {
        $metrics['sorties']++;
        $metrics['turns_completed_total'] += $result->turnsCompleted;
        $metrics['t20_reached'] += $result->turnsCompleted >= 20 ? 1 : 0;
        $metrics['reservation_failures'] += $result->reservationFailureCount;
        $metrics['forms_by_character'][$characterKey][$result->form] = true;
        $metrics['stage_sorties'][$stage]++;
        foreach ($result->ultimateDenialReasons as $reason) {
            $metrics['ultimate_denial_reasons'][$reason] = (int) ($metrics['ultimate_denial_reasons'][$reason] ?? 0) + 1;
        }
        foreach ($result->turns as $turn) {
            $metrics['observations'] += ($turn['pending_kind'] ?? null) === 'observation' ? 1 : 0;
            $metrics['counterplay_applied'] += (($turn['counterplay']['applied'] ?? false) === true) ? 1 : 0;
            if (($turn['enemy_action_id'] ?? null) === 'ten_lineage_end') {
                $metrics['ultimate_executed']++;
            }
            if (is_array($turn['enemy_damage'] ?? null)) {
                $metrics['enemy_damage_actions']++;
                $metrics['cap_binding_hits'] += ($turn['enemy_damage']['beforeCap'] ?? 0) > ($turn['enemy_damage']['afterCap'] ?? 0) ? 1 : 0;
                $defense = is_array($turn['enemy_damage']['playerDefense'] ?? null)
                    ? $turn['enemy_damage']['playerDefense']
                    : [];
                $metrics['guard_consumed_actions'] += ($defense['guard_consumed'] ?? false) === true ? 1 : 0;
                $metrics['parry_succeeded_actions'] += ($defense['parry_succeeded'] ?? false) === true ? 1 : 0;
                $metrics['guts_triggered_actions'] += ($defense['guts_triggered'] ?? false) === true ? 1 : 0;
                $metrics['actual_hp_loss'] += max(0, (int) ($defense['actual_hp_loss'] ?? 0));
            }
            foreach (($turn['player_damage']['sources'] ?? []) as $source) {
                if (is_array($source) && ($source['kind'] ?? null) === NationRaidRules::DAMAGE_COUNTER) {
                    $metrics['counter_damage'] += max(0, (int) ($source['applied_damage'] ?? 0));
                }
            }
        }
    }

    /** @param array<string, mixed> $result */
    private function accumulateResolvedBattleMetrics(array &$metrics, array $result, int $stage, string $characterKey): void
    {
        $resolved = is_array($result['metrics'] ?? null) ? $result['metrics'] : [];
        $metrics['sorties']++;
        $metrics['turns_completed_total'] += (int) $result['turns_completed'];
        $metrics['t20_reached'] += (int) $result['turns_completed'] >= NationRaidRules::MAX_TURNS ? 1 : 0;
        $metrics['ultimate_executed'] += (int) ($resolved['ultimate_executed'] ?? 0);
        $metrics['reservation_failures'] += (int) $result['reservation_failure_count'];
        $metrics['observations'] += (int) ($resolved['observations'] ?? 0);
        $metrics['cap_binding_hits'] += (int) ($resolved['cap_binding_hits'] ?? 0);
        $metrics['enemy_damage_actions'] += (int) ($resolved['enemy_damage_actions'] ?? 0);
        $metrics['counterplay_applied'] += (int) ($resolved['counterplay_applied'] ?? 0);
        $metrics['guard_consumed_actions'] += (int) ($resolved['guard_consumed_actions'] ?? 0);
        $metrics['parry_succeeded_actions'] += (int) ($resolved['parry_succeeded_actions'] ?? 0);
        $metrics['guts_triggered_actions'] += (int) ($resolved['guts_triggered_actions'] ?? 0);
        $metrics['actual_hp_loss'] += (int) ($resolved['actual_hp_loss'] ?? 0);
        $metrics['counter_damage'] += (int) ($resolved['counter_damage'] ?? 0);
        $metrics['forms_by_character'][$characterKey][$result['form']] = true;
        $metrics['stage_sorties'][$stage]++;
        foreach ($result['ultimate_denial_reasons'] as $reason) {
            $metrics['ultimate_denial_reasons'][$reason] = (int) ($metrics['ultimate_denial_reasons'][$reason] ?? 0) + 1;
        }
    }

    /** @return array<string, mixed> */
    private function finalizeBattleMetrics(array $metrics): array
    {
        $uniqueForms = [];
        foreach ($metrics['forms_by_character'] as $forms) {
            $uniqueForms[] = count($forms);
        }
        unset($metrics['forms_by_character']);
        $metrics['mean_turns_completed'] = $metrics['sorties'] > 0
            ? $metrics['turns_completed_total'] / $metrics['sorties']
            : 0.0;
        $metrics['t20_reach_rate'] = $metrics['sorties'] > 0 ? $metrics['t20_reached'] / $metrics['sorties'] : 0.0;
        $metrics['ultimate_execution_rate_per_t20'] = $metrics['t20_reached'] > 0
            ? $metrics['ultimate_executed'] / $metrics['t20_reached']
            : 0.0;
        $metrics['cap_binding_rate'] = $metrics['enemy_damage_actions'] > 0
            ? $metrics['cap_binding_hits'] / $metrics['enemy_damage_actions']
            : 0.0;
        $metrics['characters_experiencing_all_four_forms'] = count(array_filter($uniqueForms, static fn (int $count): bool => $count >= 4));

        return $metrics;
    }

    /** @param array<string, array{sorties:int,damage:int}> $personal @return array<string, float|int> */
    private function rewardMetrics(array $personal, bool $stage10, bool $completed): array
    {
        $valid = array_filter($personal, static fn (array $row): bool => $row['sorties'] >= 15);
        $count = count($valid);
        $threshold = static fn (int $damage): int => count(array_filter($valid, static fn (array $row): bool => $row['damage'] >= $damage));

        return [
            'valid_participants' => $count,
            'under_250k_despite_valid' => $count > 0 ? ($count - $threshold(250_000)) / $count : 0.0,
            'reach_250k' => $count > 0 ? $threshold(250_000) / $count : 0.0,
            'reach_1m' => $count > 0 ? $threshold(1_000_000) / $count : 0.0,
            'reach_2m' => $count > 0 ? $threshold(2_000_000) / $count : 0.0,
            'server_progress_net_stamina' => $completed ? 50 : ($stage10 ? -50 : -200),
        ];
    }

    /** @param list<array<string, mixed>> $metrics @return array<string, mixed> */
    private function sumBattleMetrics(array $metrics): array
    {
        if ($metrics === []) {
            return [];
        }
        $keys = [
            'sorties',
            'turns_completed_total',
            't20_reached',
            'ultimate_executed',
            'reservation_failures',
            'observations',
            'cap_binding_hits',
            'enemy_damage_actions',
            'counterplay_applied',
            'guard_consumed_actions',
            'parry_succeeded_actions',
            'guts_triggered_actions',
            'actual_hp_loss',
            'counter_damage',
            'characters_experiencing_all_four_forms',
        ];
        $sum = array_fill_keys($keys, 0);
        $denial = [];
        $stageSorties = array_fill(1, NationRaidRules::MAX_STAGES, 0);
        foreach ($metrics as $metric) {
            foreach ($keys as $key) {
                $sum[$key] += (int) ($metric[$key] ?? 0);
            }
            foreach (($metric['ultimate_denial_reasons'] ?? []) as $reason => $count) {
                $denial[$reason] = (int) ($denial[$reason] ?? 0) + (int) $count;
            }
            foreach (($metric['stage_sorties'] ?? []) as $stage => $count) {
                $stageSorties[$stage] += (int) $count;
            }
        }
        $sum['ultimate_denial_reasons'] = $denial;
        $sum['stage_sorties'] = $stageSorties;
        $sum['mean_turns_completed'] = $sum['sorties'] > 0 ? $sum['turns_completed_total'] / $sum['sorties'] : 0.0;
        $sum['t20_reach_rate'] = $sum['sorties'] > 0 ? $sum['t20_reached'] / $sum['sorties'] : 0.0;
        $sum['ultimate_execution_rate_per_t20'] = $sum['t20_reached'] > 0 ? $sum['ultimate_executed'] / $sum['t20_reached'] : 0.0;
        $sum['cap_binding_rate'] = $sum['enemy_damage_actions'] > 0 ? $sum['cap_binding_hits'] / $sum['enemy_damage_actions'] : 0.0;

        return $sum;
    }

    /** @param list<array<string, float|int>> $metrics @return array<string, float> */
    private function meanRewardMetrics(array $metrics): array
    {
        $keys = ['valid_participants', 'under_250k_despite_valid', 'reach_250k', 'reach_1m', 'reach_2m', 'server_progress_net_stamina'];
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->mean(array_map(static fn (array $row): float => (float) ($row[$key] ?? 0), $metrics));
        }

        return $result;
    }

    /** @param list<int|float> $values @return array{min:float,p10:float,median:float,p90:float,max:float}|array{} */
    private function distribution(array $values): array
    {
        if ($values === []) {
            return [];
        }
        sort($values, SORT_NUMERIC);

        return [
            'min' => (float) $values[0],
            'p10' => (float) $this->quantile($values, 0.10),
            'median' => (float) $this->quantile($values, 0.50),
            'p90' => (float) $this->quantile($values, 0.90),
            'max' => (float) $values[array_key_last($values)],
        ];
    }

    /** @param list<int|float> $values */
    private function quantile(array $values, float $rate): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values, SORT_NUMERIC);
        $position = (count($values) - 1) * $rate;
        $lower = (int) floor($position);
        $upper = (int) ceil($position);
        if ($lower === $upper) {
            return (float) $values[$lower];
        }
        $fraction = $position - $lower;

        return ((float) $values[$lower] * (1 - $fraction)) + ((float) $values[$upper] * $fraction);
    }

    /** @param list<int|float> $values */
    private function mean(array $values): float
    {
        return $values === [] ? 0.0 : array_sum($values) / count($values);
    }

    /** @param list<bool> $values */
    private function meanBooleans(array $values): float
    {
        return $values === [] ? 0.0 : count(array_filter($values)) / count($values);
    }

    private function scenarioKey(float $rate, string $exclusion, string $denominator): string
    {
        $percent = (int) round($rate * 100);

        return $exclusion === 'none'
            ? "participation_{$percent}"
            : "without_top_10_participation_{$percent}_{$denominator}";
    }

    private function derivedSeed(int $seed, string $scope): int
    {
        return max(1, (int) hexdec(substr(hash('sha256', "{$seed}|{$scope}"), 0, 7)));
    }

    private function scoreHex(int $seed, string $scope): string
    {
        return substr(hash('sha256', "{$seed}|{$scope}"), 0, 16);
    }

    private function uniform(int $seed, string $scope): float
    {
        $integer = hexdec(substr(hash('sha256', "{$seed}|{$scope}"), 0, 13));

        return max(1.0e-12, min(1.0 - 1.0e-12, ($integer + 1) / 4_503_599_627_370_497));
    }

    /** @return array<string, mixed> */
    private function playerSnapshotModel(): array
    {
        $contract = [
            'version' => self::PLAYER_SNAPSHOT_MODEL_VERSION,
            'authoritative_for_balance_gate' => self::PLAYER_SNAPSHOT_MODEL_AUTHORITATIVE,
            'enemy_hit_chance' => [
                'source' => 'current_pve_calculate_hit_chance',
                'base' => self::PVE_HIT_BASE,
                'agility_factor' => self::PVE_HIT_AGILITY_FACTOR,
                'minimum' => self::PVE_HIT_MIN,
                'maximum' => self::PVE_HIT_MAX,
            ],
            'enemy_critical_chance' => [
                'source' => 'current_pve_critical_chance',
                'base' => self::PVE_CRITICAL_BASE,
                'luck_factor' => self::PVE_CRITICAL_LUCK_FACTOR,
                'minimum' => self::PVE_CRITICAL_MIN,
                'maximum' => self::PVE_CRITICAL_MAX,
            ],
            'known_gaps' => [
                'active_evasion_rate_is_zero',
                'persistent_final_damage_reduction_rate_is_zero',
            ],
        ];
        $contract['hash'] = hash('sha256', NationRaidJson::encode($contract, JSON_UNESCAPED_UNICODE));

        return $contract;
    }

    /** @return array<string, mixed> */
    private function resolvedProfileModel(): array
    {
        $contract = [
            'version' => NationRaidResolvedProfileProjector::MODEL_VERSION,
            'authoritative_for_balance_gate' => true,
            'action_profile_authoritative' => true,
            'coordination_model_authoritative' => true,
            'source' => NationRaidTurnByTurnActionProfileBridge::class,
            'context_contract_hash' => NationRaidResolvedProfileContext::contractHash(),
            'known_gaps' => [],
        ];
        $contract['hash'] = hash('sha256', NationRaidJson::encode($contract, JSON_UNESCAPED_UNICODE));

        return $contract;
    }

    /** @return array<string, mixed> */
    private function coordinationModel(): array
    {
        $contract = $this->coordinationTiming->contract();
        $contract['hash'] = $this->coordinationTiming->contractHash();

        return $contract;
    }
}
