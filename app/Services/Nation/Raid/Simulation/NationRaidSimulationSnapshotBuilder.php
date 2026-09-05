<?php

namespace App\Services\Nation\Raid\Simulation;

use App\Models\BattleLog;
use App\Models\Character;
use App\Models\Nation;
use App\Models\Skill;
use App\Services\AuthService;
use App\Services\CharacterStatusService;
use App\Services\EquipmentPermissionService;
use App\Services\JobArtLineageCatalog;
use App\Services\JobArtService;
use App\Services\JobArtV2DeckRoleResolution;
use App\Services\JobArtV2DeckRoleResolver;
use App\Services\Nation\Raid\NationRaidJson;
use App\Services\Nation\Raid\NationRaidPlayerSnapshot;
use App\Services\Nation\Raid\NationRaidRules;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/** Phase 2用の匿名・読み取り専用player population snapshotを構築する。 */
final class NationRaidSimulationSnapshotBuilder
{
    public const SCHEMA_VERSION = 'nation-raid-phase2-snapshot-v6';

    public function __construct(
        private readonly CharacterStatusService $statusService,
        private readonly EquipmentPermissionService $equipmentPermissionService,
        private readonly JobArtService $jobArtService,
        private readonly JobArtLineageCatalog $lineageCatalog,
        private readonly JobArtV2DeckRoleResolver $deckRoleResolver,
        private readonly AuthService $authService,
        private readonly NationRaidRules $rules,
        private readonly NationRaidCoordinationTimingModel $coordinationTiming,
        private readonly NationRaidSimulationLineageAdapter $lineageAdapter,
        private readonly NationRaidSimulationAnonymizer $anonymizer,
        private readonly NationRaidSimulationActionProfileProvider $actionProfiles,
        private readonly NationRaidSimulationProfileCacheHasher $profileCacheHasher,
        private readonly NationRaidResolvedContextProfileFactory $resolvedProfileFactory,
        private readonly NationRaidResolvedProfileCacheHasher $resolvedProfileCacheHasher,
        private readonly NationRaidResolvedContextPlan $resolvedContextPlan,
        private readonly NationRaidKillerPopulationSummary $killerPopulationSummary,
    ) {}

    /**
     * @param  list<array{stage:int,starting_form:string,strategy:string,dominant_lineage:?string}>  $resolvedContexts
     * @return array<string, mixed>
     */
    public function build(
        ?CarbonImmutable $extractedAt = null,
        int $activeDays = 7,
        int $profileCount = 7,
        array $resolvedContexts = [],
        bool $resolvedContextCoverageComplete = false,
    ): array {
        $extractedAt ??= CarbonImmutable::now();
        if ($activeDays !== 7) {
            throw new InvalidArgumentException('Phase 2 active window is fixed at seven days.');
        }
        $profileCount = max(1, min(25, $profileCount));
        $resolvedContexts = $this->resolvedContextPlan->normalize($resolvedContexts);
        if ($resolvedContextCoverageComplete && $resolvedContexts === []) {
            throw new InvalidArgumentException('Resolved context coverage cannot be complete with an empty plan.');
        }
        $from = $extractedAt->subDays($activeDays);

        $candidates = Character::query()
            ->with(['user', 'currentJob', 'nationMembership.nation', 'jobHistories.jobClass'])
            ->whereNotNull('last_battle_at')
            ->where('last_battle_at', '>=', $from)
            ->orderBy('id')
            ->get();

        $activity = $this->activityFor($candidates->pluck('id')->map(fn ($id): int => (int) $id)->all(), $from, $extractedAt);
        $report = [
            'candidate_characters' => $candidates->count(),
            'included_characters' => 0,
            'extraction_error_characters' => 0,
            'raid_killer_matched_characters' => 0,
            'raid_killer_unmatched_characters' => 0,
            'raid_killer_unavailable_characters' => 0,
            'excluded_missing_user' => 0,
            'excluded_admin_or_tester' => 0,
            'excluded_guest' => 0,
            'excluded_frozen' => 0,
            'active_nation_characters' => 0,
            'unaffiliated_characters' => 0,
            'inactive_nation_memberships_treated_as_unaffiliated' => 0,
            'anonymous_nations' => 0,
            'coordination_timing_samples' => 0,
        ];
        $rows = [];
        $nationKeys = [];

        foreach ($candidates as $character) {
            if ($character->user === null) {
                $report['excluded_missing_user']++;

                continue;
            }
            if ($character->isExcludedFromPublicLogs()) {
                $report['excluded_admin_or_tester']++;

                continue;
            }
            if ($this->authService->isGuestUser($character->user)) {
                $report['excluded_guest']++;

                continue;
            }
            if ((bool) $character->is_frozen) {
                $report['excluded_frozen']++;

                continue;
            }

            $row = $this->baseAnonymousRow($character, $activity[(int) $character->id] ?? $this->emptyActivity());
            $report['coordination_timing_samples'] += count($row['activity']['minute_of_day_samples']);
            $nation = $character->nationMembership?->nation;
            if ($nation instanceof Nation && $nation->status === Nation::STATUS_ACTIVE) {
                $row['nation_key'] = $this->anonymizer->nationKey((int) $nation->id);
                $nationKeys[$row['nation_key']] = true;
                $report['active_nation_characters']++;
            } else {
                $row['nation_key'] = null;
                $report['unaffiliated_characters']++;
                if ($nation instanceof Nation) {
                    $report['inactive_nation_memberships_treated_as_unaffiliated']++;
                }
            }

            try {
                $this->completeRow($row, $character, $profileCount, $resolvedContexts);
                if (($row['raid_killer']['matched'] ?? false) === true) {
                    $report['raid_killer_matched_characters']++;
                } else {
                    $report['raid_killer_unmatched_characters']++;
                }
            } catch (Throwable) {
                // 直接識別子や例外messageを成果物へ出さず、schema validatorへ欠損を渡す。
                $row['extraction_error_codes'] = ['snapshot_calculation_failed'];
                $report['extraction_error_characters']++;
                $report['raid_killer_unavailable_characters']++;
            }

            $rows[] = $row;
            $report['included_characters']++;
        }

        $report['anonymous_nations'] = count($nationKeys);
        $killerPopulation = $this->killerPopulationSummary->summarize($rows);
        $report['raid_killer_matched_characters'] = $killerPopulation['matched_characters'];
        $report['raid_killer_unmatched_characters'] = $killerPopulation['unmatched_characters'];
        $report['raid_killer_unavailable_characters'] = $killerPopulation['unavailable_characters'];
        $report['raid_killer_match_rate'] = $killerPopulation['match_rate'];
        $report['raid_killer_average_damage_rate'] = $killerPopulation['average_damage_rate'];
        $report['raid_killer_max_damage_rate'] = $killerPopulation['max_damage_rate'];
        $report['raid_killer_max_raw_combined_damage_rate'] = $killerPopulation['max_raw_combined_damage_rate'];
        $report['raid_killer_cap_binding_characters'] = $killerPopulation['cap_binding_characters'];
        $report['raid_killer_damage_rate_distribution'] = $killerPopulation['damage_rate_distribution'];

        $rulesetHash = $this->rules->rulesetHash();
        $raidKillerContractHash = $this->raidKillerContractHash();
        $resolvedContextPlanHash = $this->resolvedContextPlan->hash(
            $resolvedContexts,
            $resolvedContextCoverageComplete,
        );
        $integrationHash = hash('sha256', NationRaidJson::encode([
            'version' => 'nation-raid-phase2-snapshot-builder-v6',
            'schema' => self::SCHEMA_VERSION,
            'ruleset_hash' => $rulesetHash,
            'coordination_timing_model_hash' => $this->coordinationTiming->contractHash(),
            'lineage_adapter_hash' => $this->lineageAdapter->contractHash(),
            'action_profile_model' => $this->actionProfiles->modelVersion(),
            'resolved_context_profile_model' => $this->resolvedProfileFactory->modelVersion(),
            'resolved_context_contract_hash' => $this->resolvedProfileFactory->contextContractHash(),
            'resolved_context_plan_schema' => NationRaidResolvedContextPlan::SCHEMA_VERSION,
            'resolved_context_plan_coverage_complete' => $resolvedContextCoverageComplete,
            'resolved_context_plan_hash' => $resolvedContextPlanHash,
            'status_path' => CharacterStatusService::class.'::getFinalStats',
            'boss_set_path' => JobArtService::class.'::battleArtsFor:boss',
            'raid_killer_contract_hash' => $raidKillerContractHash,
        ], JSON_UNESCAPED_UNICODE));

        $actionProfileCacheHash = $this->profileCacheHasher->rootCacheHash(
            $rulesetHash,
            $integrationHash,
            $this->actionProfiles->modelVersion(),
            $rows,
        );
        $resolvedContextProfileCacheHash = $this->resolvedProfileCacheHasher->rootCacheHash(
            $rulesetHash,
            $integrationHash,
            $this->resolvedProfileFactory->modelVersion(),
            $this->resolvedProfileFactory->contextContractHash(),
            $rows,
        );
        $resolvedContextProfileAuthoritative = $resolvedContextCoverageComplete
            && $resolvedContexts !== []
            && $report['extraction_error_characters'] === 0;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'extracted_at' => $extractedAt->toIso8601String(),
            'active_window' => [
                'days' => $activeDays,
                'from' => $from->toIso8601String(),
                'to' => $extractedAt->toIso8601String(),
            ],
            'ruleset_hash' => $rulesetHash,
            'boss_species_key' => NationRaidRules::BOSS_SPECIES_KEY,
            'raid_killer_contract_hash' => $raidKillerContractHash,
            'coordination_timing_model' => $this->coordinationTiming->contract(),
            'coordination_timing_model_hash' => $this->coordinationTiming->contractHash(),
            'integration_hash' => $integrationHash,
            'lineage_adapter_hash' => $this->lineageAdapter->contractHash(),
            'anonymizer_key_id' => $this->anonymizer->keyId(),
            'action_profile_model' => $this->actionProfiles->modelVersion(),
            'action_profile_authoritative' => $this->actionProfiles->authoritativeForBalanceGate(),
            'action_profile_cache_hash' => $actionProfileCacheHash,
            'action_profiles_per_character' => $profileCount,
            'resolved_context_profile_model' => $this->resolvedProfileFactory->modelVersion(),
            'resolved_context_profile_authoritative' => $resolvedContextProfileAuthoritative,
            'resolved_context_profile_cache_hash' => $resolvedContextProfileCacheHash,
            'resolved_context_profiles_per_context' => $profileCount,
            'resolved_context_contract_hash' => $this->resolvedProfileFactory->contextContractHash(),
            'resolved_context_plan_schema' => NationRaidResolvedContextPlan::SCHEMA_VERSION,
            'resolved_context_plan_coverage_complete' => $resolvedContextCoverageComplete,
            'resolved_context_plan_hash' => $resolvedContextPlanHash,
            'resolved_context_plan' => $resolvedContexts,
            'feature_flags' => $this->featureFlagSnapshot(),
            'population_report' => $report,
            'characters' => $rows,
        ];
    }

    /** @param array<string, mixed> $row */
    private function completeRow(
        array &$row,
        Character $character,
        int $profileCount,
        array $resolvedContexts,
    ): void {
        CharacterStatusService::clearRequestCache((int) $character->id);
        $stats = $this->statusService->getFinalStats($character);
        foreach (['max_hp', 'max_mp', 'str', 'def', 'mag', 'spr', 'agi', 'luk'] as $key) {
            if (! array_key_exists($key, $stats) || ! is_int($stats[$key])) {
                throw new \RuntimeException("Missing final ability: {$key}");
            }
        }

        $arts = $this->jobArtService->battleArtsFor($character, 'boss');
        $set = array_fill(0, 5, null);
        $setDetails = [];
        $votes = [];
        foreach ($arts as $skill) {
            if (! $skill instanceof Skill) {
                continue;
            }
            $slot = (int) $skill->getAttribute('slot_no');
            if ($slot < 1 || $slot > 5) {
                throw new \RuntimeException('Boss set contains an out-of-range slot.');
            }
            $identity = JobArtV2DeckRoleResolution::artKey($skill);
            $lineage = $this->lineageCatalog->forArt($skill);
            $canonicalLineage = $lineage['lineage_key'] ?? null;
            $raidLineage = is_string($canonicalLineage)
                ? $this->lineageAdapter->toRaid($canonicalLineage)
                : null;
            $set[$slot - 1] = $identity;
            if ($raidLineage !== null) {
                $votes[$raidLineage] = true;
            }
            $setDetails[] = [
                'slot' => $slot,
                'exact_identity' => $identity,
                'canonical_lineage' => $canonicalLineage,
                'raid_lineage' => $raidLineage,
                'is_raid_counterplay' => $this->rules->counterplayArt($identity) !== null,
            ];
        }
        usort($setDetails, static fn (array $a, array $b): int => $a['slot'] <=> $b['slot']);

        $roleResolution = $this->deckRoleResolver->resolveSkills($character->current_job_id, $arts);
        $row['abilities'] = [
            'max_hp' => $stats['max_hp'],
            'max_sp' => $stats['max_mp'],
            'attack' => $stats['str'],
            'defense' => $stats['def'],
            'magic' => $stats['mag'],
            'spirit' => $stats['spr'],
            'agility' => $stats['agi'],
            'luck' => $stats['luk'],
        ];
        $row['job'] = [
            'current_job_key' => trim((string) ($character->currentJob?->key ?? '')),
            'mastered_job_count' => $character->jobHistories->filter(
                static fn ($history): bool => (bool) $history->is_mastered || (int) $history->job_level >= 10,
            )->count(),
            'counterplay_enabled' => $roleResolution->active && $this->requiredJobArtFlagsEnabled(),
            'formal_canonical_lineages' => $roleResolution->formalLineages(),
        ];
        $row['boss_set_exact_identities'] = $set;
        $row['boss_set'] = $setDetails;
        $row['lineage_votes'] = array_keys($votes);
        $weapon = $character->characterItems()
            ->where('is_equipped', true)
            ->whereHas('item', fn ($query) => $query->where('type', 'weapon'))
            ->with(['item', 'affixPrefix', 'affixSuffix'])
            ->first();
        $effects = $weapon !== null
            ? $this->equipmentPermissionService->effectiveKillerEffects($character, $weapon)
            : [];
        $matchingEffects = array_values(array_filter(
            $effects,
            static fn (array $effect): bool => ($effect['species_key'] ?? null) === NationRaidRules::BOSS_SPECIES_KEY,
        ));
        $matchingRate = NationRaidRules::raidKillerDamageRate(
            array_sum(array_column($matchingEffects, 'damage_rate')),
        );
        $row['raid_killer'] = [
            'boss_species_key' => NationRaidRules::BOSS_SPECIES_KEY,
            'matched' => $matchingRate > 0.0,
            'damage_rate' => round($matchingRate, 6),
            'damage_rate_cap' => NationRaidRules::BOSS_KILLER_DAMAGE_RATE_CAP,
            'effects' => array_map(static fn (array $effect): array => [
                'source' => (string) $effect['source'],
                'species_key' => (string) $effect['species_key'],
                'damage_rate' => round((float) $effect['damage_rate'], 6),
            ], $matchingEffects),
        ];
        $armor = $character->characterItems()
            ->where('is_equipped', true)
            ->whereHas('item', fn ($query) => $query->where('type', 'armor'))
            ->with('item')
            ->first();
        $resistanceRate = NationRaidRules::ARMOR_SPECIES_RESISTANCE_ENABLED
            && $armor !== null
            && $armor->resist_species_key === NationRaidRules::BOSS_SPECIES_KEY
                ? min(
                    NationRaidRules::ARMOR_SPECIES_RESISTANCE_RATE_CAP,
                    $this->equipmentPermissionService->effectiveSpeciesDamageReductionRate($character, $armor),
                )
                : 0.0;
        $row['raid_resistance'] = [
            'boss_species_key' => NationRaidRules::BOSS_SPECIES_KEY,
            'matched' => $resistanceRate > 0.0,
            'damage_reduction_rate' => round($resistanceRate, 6),
            'damage_reduction_rate_cap' => NationRaidRules::ARMOR_SPECIES_RESISTANCE_RATE_CAP,
        ];
        $profiles = $this->profileCacheHasher->sealProfiles(
            $this->actionProfiles->profilesFor($character, $profileCount),
        );
        $row['action_profiles'] = $profiles;
        $row['action_profile_cache_hash'] = $this->profileCacheHasher->characterCacheHash($profiles);

        $player = new NationRaidPlayerSnapshot(
            maxHp: $row['abilities']['max_hp'],
            defense: $row['abilities']['defense'],
            spirit: $row['abilities']['spirit'],
            maxSp: $row['abilities']['max_sp'],
            finalDamageReductionRate: $row['raid_resistance']['damage_reduction_rate'],
            counterplayEnabled: $row['job']['counterplay_enabled'],
            bossSetExactIdentities: $row['boss_set_exact_identities'],
        );
        $resolvedProfiles = [];
        foreach ($resolvedContexts as $context) {
            $resolvedProfiles = [
                ...$resolvedProfiles,
                ...$this->resolvedProfileFactory->profilesForContext(
                    character: $character,
                    player: $player,
                    characterKey: $row['character_key'],
                    stage: $context['stage'],
                    startingForm: $context['starting_form'],
                    strategy: $context['strategy'],
                    dominantLineage: $context['dominant_lineage'],
                    profileCount: $profileCount,
                ),
            ];
        }
        $resolvedProfiles = $this->resolvedProfileCacheHasher->sealProfiles($resolvedProfiles);
        $row['resolved_context_profiles'] = $resolvedProfiles;
        $row['resolved_context_profile_cache_hash'] = $this->resolvedProfileCacheHasher
            ->characterCacheHash($resolvedProfiles);
    }

    /** @param array<string, mixed> $activity @return array<string, mixed> */
    private function baseAnonymousRow(Character $character, array $activity): array
    {
        return [
            'participant_key' => $this->anonymizer->participantKey((int) $character->user_id),
            'character_key' => $this->anonymizer->characterKey((int) $character->id),
            'nation_key' => null,
            'activity' => $activity,
            'participation_cluster' => [
                'days' => 7,
                'daily_slot_cap' => 5,
                'event_slot_cap' => 35,
            ],
        ];
    }

    /**
     * @param  list<int>  $characterIds
     * @return array<int, array<string, mixed>>
     */
    private function activityFor(array $characterIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($characterIds === []) {
            return [];
        }

        $activity = [];
        foreach ($characterIds as $characterId) {
            $activity[$characterId] = $this->emptyActivity();
        }

        $logs = BattleLog::query()
            ->actualBattles()
            ->whereIn('character_id', $characterIds)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->orderBy('character_id')
            ->orderBy('created_at')
            ->get(['character_id', 'created_at', 'damage_dealt']);

        foreach ($logs as $log) {
            $characterId = (int) $log->character_id;
            if (! isset($activity[$characterId])) {
                continue;
            }
            $day = (int) floor($from->diffInSeconds(CarbonImmutable::parse($log->created_at), false) / 86_400);
            if ($day < 0 || $day > 6) {
                continue;
            }
            $activity[$characterId]['battles_7d']++;
            $activity[$characterId]['daily_battle_counts'][$day]++;
            $localTime = CarbonImmutable::parse($log->created_at)
                ->setTimezone((string) config('app.timezone', 'UTC'));
            $activity[$characterId]['minute_of_day_samples'][] = ((int) $localTime->format('G') * 60)
                + (int) $localTime->format('i');
            if ($log->damage_dealt !== null && is_numeric($log->damage_dealt)) {
                $damage = max(0, (int) $log->damage_dealt);
                $activity[$characterId]['observed_damage_samples']++;
                $activity[$characterId]['observed_damage_total'] += $damage;
                $activity[$characterId]['observed_damage_max'] = max(
                    $activity[$characterId]['observed_damage_max'],
                    $damage,
                );
            }
        }

        // last_battle_atがactive判定の正本。telemetry導入前などでbattle_logsが欠ける場合も
        // 0へ補完せず、validatorが入力不足として停止させる。
        foreach ($activity as &$row) {
            sort($row['minute_of_day_samples'], SORT_NUMERIC);
        }
        unset($row);

        return $activity;
    }

    /** @return array{battles_7d:int,daily_battle_counts:list<int>,minute_of_day_samples:list<int>,observed_damage_samples:int,observed_damage_total:int,observed_damage_max:int} */
    private function emptyActivity(): array
    {
        return [
            'battles_7d' => 0,
            'daily_battle_counts' => array_fill(0, 7, 0),
            'minute_of_day_samples' => [],
            'observed_damage_samples' => 0,
            'observed_damage_total' => 0,
            'observed_damage_max' => 0,
        ];
    }

    /** @return array<string, bool> */
    private function featureFlagSnapshot(): array
    {
        return [
            'dynamic_single' => (bool) config('battle.job_art_v2.dynamic_single', false),
            'hit_resolution' => (bool) config('battle.job_art_v2.hit_resolution', false),
            'damage_application' => (bool) config('battle.job_art_v2.damage_application', false),
            'resources' => (bool) config('battle.job_art_v2.resources', false),
        ];
    }

    private function requiredJobArtFlagsEnabled(): bool
    {
        return ! in_array(false, $this->featureFlagSnapshot(), true);
    }

    private function raidKillerContractHash(): string
    {
        return hash('sha256', NationRaidJson::encode([
            'version' => 'nation-raid-killer-v2',
            'boss_species_key' => NationRaidRules::BOSS_SPECIES_KEY,
            'killer_damage_rates' => config('equipment_affix.killer_damage_rates', []),
            'quality_multipliers' => config('equipment_affix.quality_multipliers', []),
            'raid_damage_rate_multiplier' => NationRaidRules::BOSS_KILLER_DAMAGE_RATE_MULTIPLIER,
            'damage_rate_cap' => NationRaidRules::BOSS_KILLER_DAMAGE_RATE_CAP,
            'application' => 'player_to_boss_only_once_in_current_player_engine',
        ], JSON_UNESCAPED_UNICODE));
    }
}
