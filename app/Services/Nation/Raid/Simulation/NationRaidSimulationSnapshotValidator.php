<?php

namespace App\Services\Nation\Raid\Simulation;

use App\Services\Nation\Raid\NationRaidPlayerActionSnapshot;
use App\Services\Nation\Raid\NationRaidRules;
use Carbon\CarbonImmutable;
use Throwable;

/** 匿名snapshotをsimulation開始前に検査し、欠損を0へ補正しない。 */
final class NationRaidSimulationSnapshotValidator
{
    private const SCHEMA_VERSION = 'nation-raid-phase2-snapshot-v6';

    private readonly NationRaidSimulationProfileCacheHasher $profileCacheHasher;

    private readonly NationRaidResolvedProfileCacheHasher $resolvedProfileCacheHasher;

    private readonly NationRaidResolvedProfileValidator $resolvedProfileValidator;

    private readonly NationRaidResolvedContextPlan $resolvedContextPlan;

    private readonly NationRaidCoordinationTimingModel $coordinationTiming;

    public function __construct(
        ?NationRaidSimulationProfileCacheHasher $profileCacheHasher = null,
        ?NationRaidResolvedProfileCacheHasher $resolvedProfileCacheHasher = null,
        ?NationRaidResolvedProfileValidator $resolvedProfileValidator = null,
        ?NationRaidResolvedContextPlan $resolvedContextPlan = null,
        ?NationRaidCoordinationTimingModel $coordinationTiming = null,
    ) {
        $this->profileCacheHasher = $profileCacheHasher ?? new NationRaidSimulationProfileCacheHasher;
        $this->resolvedProfileCacheHasher = $resolvedProfileCacheHasher ?? new NationRaidResolvedProfileCacheHasher;
        $this->resolvedProfileValidator = $resolvedProfileValidator
            ?? new NationRaidResolvedProfileValidator($this->resolvedProfileCacheHasher);
        $this->resolvedContextPlan = $resolvedContextPlan ?? new NationRaidResolvedContextPlan;
        $this->coordinationTiming = $coordinationTiming ?? new NationRaidCoordinationTimingModel;
    }

    /** @return array{ready:bool,errors:list<array{character_key:?string,reason:string}>,warnings:list<string>,counts:array<string,int>} */
    public function validate(array $snapshot): array
    {
        $errors = [];
        $warnings = [];
        $characters = is_array($snapshot['characters'] ?? null) ? $snapshot['characters'] : [];

        if (($snapshot['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            $errors[] = ['character_key' => null, 'reason' => 'unsupported_schema_version'];
        }
        if (($snapshot['boss_species_key'] ?? null) !== NationRaidRules::BOSS_SPECIES_KEY) {
            $errors[] = ['character_key' => null, 'reason' => 'invalid_boss_species_key'];
        }
        foreach (['extracted_at', 'ruleset_hash', 'integration_hash', 'action_profile_model', 'resolved_context_profile_model'] as $key) {
            if (! is_string($snapshot[$key] ?? null) || trim($snapshot[$key]) === '') {
                $errors[] = ['character_key' => null, 'reason' => "missing_root_{$key}"];
            }
        }
        foreach (['ruleset_hash', 'integration_hash', 'action_profile_cache_hash', 'resolved_context_profile_cache_hash', 'resolved_context_contract_hash', 'resolved_context_plan_hash', 'coordination_timing_model_hash'] as $key) {
            if (is_string($snapshot[$key] ?? null) && ! preg_match('/^[a-f0-9]{64}$/', $snapshot[$key])) {
                $errors[] = ['character_key' => null, 'reason' => "invalid_root_{$key}"];
            }
        }
        if (! is_string($snapshot['raid_killer_contract_hash'] ?? null)
            || ! preg_match('/^[a-f0-9]{64}$/', $snapshot['raid_killer_contract_hash'])) {
            $errors[] = ['character_key' => null, 'reason' => 'invalid_raid_killer_contract_hash'];
        }
        if (! is_string($snapshot['lineage_adapter_hash'] ?? null)
            || ! preg_match('/^[a-f0-9]{64}$/', $snapshot['lineage_adapter_hash'])) {
            $errors[] = ['character_key' => null, 'reason' => 'invalid_root_lineage_adapter_hash'];
        }
        $coordinationModel = $snapshot['coordination_timing_model'] ?? null;
        if (! is_array($coordinationModel)) {
            $errors[] = ['character_key' => null, 'reason' => 'missing_coordination_timing_model'];
        } elseif ($coordinationModel !== $this->coordinationTiming->contract()) {
            $errors[] = ['character_key' => null, 'reason' => 'coordination_timing_model_mismatch'];
        }
        if (($snapshot['coordination_timing_model_hash'] ?? null) !== $this->coordinationTiming->contractHash()) {
            $errors[] = ['character_key' => null, 'reason' => 'coordination_timing_model_hash_mismatch'];
        }
        if (! is_string($snapshot['anonymizer_key_id'] ?? null)
            || ! preg_match('/^[a-f0-9]{16}$/', $snapshot['anonymizer_key_id'])) {
            $errors[] = ['character_key' => null, 'reason' => 'invalid_anonymizer_key_id'];
        }
        if (! is_bool($snapshot['action_profile_authoritative'] ?? null)) {
            $errors[] = ['character_key' => null, 'reason' => 'invalid_action_profile_authoritative'];
        }
        if (! is_bool($snapshot['resolved_context_profile_authoritative'] ?? null)) {
            $errors[] = ['character_key' => null, 'reason' => 'invalid_resolved_context_profile_authoritative'];
        }
        if (($snapshot['resolved_context_plan_schema'] ?? null) !== NationRaidResolvedContextPlan::SCHEMA_VERSION) {
            $errors[] = ['character_key' => null, 'reason' => 'invalid_resolved_context_plan_schema'];
        }
        if (! is_bool($snapshot['resolved_context_plan_coverage_complete'] ?? null)) {
            $errors[] = ['character_key' => null, 'reason' => 'invalid_resolved_context_plan_coverage_complete'];
        }
        $expectedProfiles = $snapshot['action_profiles_per_character'] ?? null;
        if (! is_int($expectedProfiles) || $expectedProfiles < 1 || $expectedProfiles > 25) {
            $errors[] = ['character_key' => null, 'reason' => 'invalid_action_profiles_per_character'];
            $expectedProfiles = null;
        }
        $expectedResolvedProfiles = $snapshot['resolved_context_profiles_per_context'] ?? null;
        if (! is_int($expectedResolvedProfiles) || $expectedResolvedProfiles < 1 || $expectedResolvedProfiles > 25) {
            $errors[] = ['character_key' => null, 'reason' => 'invalid_resolved_context_profiles_per_context'];
            $expectedResolvedProfiles = null;
        }
        if (($snapshot['resolved_context_contract_hash'] ?? null) !== NationRaidResolvedProfileContext::contractHash()) {
            $errors[] = ['character_key' => null, 'reason' => 'resolved_context_contract_hash_mismatch'];
        }
        $contextPlan = [];
        try {
            $contextPlanPayload = $snapshot['resolved_context_plan'] ?? null;
            if (! is_array($contextPlanPayload)) {
                throw new \InvalidArgumentException;
            }
            $contextPlan = $this->resolvedContextPlan->normalize($contextPlanPayload);
        } catch (Throwable) {
            $errors[] = ['character_key' => null, 'reason' => 'invalid_resolved_context_plan'];
        }
        $coverageComplete = ($snapshot['resolved_context_plan_coverage_complete'] ?? null) === true;
        if (is_string($snapshot['resolved_context_plan_hash'] ?? null)
            && $snapshot['resolved_context_plan_hash'] !== $this->resolvedContextPlan->hash(
                $contextPlan,
                $coverageComplete,
            )
        ) {
            $errors[] = ['character_key' => null, 'reason' => 'resolved_context_plan_hash_mismatch'];
        }
        $resolvedAuthoritative = ($snapshot['resolved_context_profile_authoritative'] ?? null) === true;
        if ($coverageComplete && $contextPlan === []) {
            $errors[] = ['character_key' => null, 'reason' => 'authoritative_resolved_context_plan_is_empty'];
        }
        if ($resolvedAuthoritative && ! $coverageComplete) {
            $errors[] = ['character_key' => null, 'reason' => 'resolved_context_profile_authoritative_without_complete_plan'];
        }
        $populationReport = is_array($snapshot['population_report'] ?? null)
            ? $snapshot['population_report']
            : [];
        $extractionErrorCount = $populationReport['extraction_error_characters'] ?? null;
        if ($resolvedAuthoritative && $extractionErrorCount !== 0) {
            $errors[] = ['character_key' => null, 'reason' => 'resolved_context_profile_authoritative_with_extraction_errors'];
        }
        if (! $this->validActiveWindow($snapshot)) {
            $errors[] = ['character_key' => null, 'reason' => 'invalid_active_window'];
        }
        if (! is_array($snapshot['population_report'] ?? null)) {
            $errors[] = ['character_key' => null, 'reason' => 'missing_population_report'];
        }
        $requiredFlags = ['dynamic_single', 'hit_resolution', 'damage_application', 'resources'];
        $featureFlags = is_array($snapshot['feature_flags'] ?? null) ? $snapshot['feature_flags'] : [];
        foreach ($requiredFlags as $flag) {
            if (($featureFlags[$flag] ?? null) !== true) {
                $errors[] = ['character_key' => null, 'reason' => "required_feature_flag_disabled:{$flag}"];
            }
        }
        if ($characters === []) {
            $errors[] = ['character_key' => null, 'reason' => 'empty_population'];
        }
        if (is_array($snapshot['population_report'] ?? null)
            && ($snapshot['population_report']['included_characters'] ?? null) !== count($characters)) {
            $errors[] = ['character_key' => null, 'reason' => 'population_report_count_mismatch'];
        }

        $participantKeys = [];
        $characterKeys = [];
        $validCharacters = 0;
        $coordinationTimingSamples = 0;
        foreach ($characters as $row) {
            $rowErrors = $this->validateCharacter(
                is_array($row) ? $row : [],
                $expectedProfiles,
                $expectedResolvedProfiles,
                is_string($snapshot['ruleset_hash'] ?? null) ? $snapshot['ruleset_hash'] : '',
                $contextPlan,
                $resolvedAuthoritative,
            );
            $characterKey = is_array($row) && is_string($row['character_key'] ?? null)
                ? $row['character_key']
                : null;
            foreach ($rowErrors as $reason) {
                $errors[] = ['character_key' => $characterKey, 'reason' => $reason];
            }
            if ($rowErrors === []) {
                $validCharacters++;
            }

            if (is_array($row)) {
                $minuteSamples = $row['activity']['minute_of_day_samples'] ?? null;
                $coordinationTimingSamples += is_array($minuteSamples) ? count($minuteSamples) : 0;
                $participantKey = $row['participant_key'] ?? null;
                if (is_string($participantKey)) {
                    if (isset($participantKeys[$participantKey])) {
                        $errors[] = ['character_key' => $characterKey, 'reason' => 'duplicate_participant_key'];
                    }
                    $participantKeys[$participantKey] = true;
                }
                if (is_string($characterKey)) {
                    if (isset($characterKeys[$characterKey])) {
                        $errors[] = ['character_key' => $characterKey, 'reason' => 'duplicate_character_key'];
                    }
                    $characterKeys[$characterKey] = true;
                }
            }
        }
        if (($populationReport['coordination_timing_samples'] ?? null) !== $coordinationTimingSamples) {
            $errors[] = ['character_key' => null, 'reason' => 'coordination_timing_sample_count_mismatch'];
        }

        if (is_string($snapshot['ruleset_hash'] ?? null)
            && is_string($snapshot['integration_hash'] ?? null)
            && is_string($snapshot['action_profile_model'] ?? null)
            && is_string($snapshot['action_profile_cache_hash'] ?? null)
            && $snapshot['action_profile_cache_hash'] !== $this->profileCacheHasher->rootCacheHash(
                $snapshot['ruleset_hash'],
                $snapshot['integration_hash'],
                $snapshot['action_profile_model'],
                $characters,
            )
        ) {
            $errors[] = ['character_key' => null, 'reason' => 'action_profile_root_cache_hash_mismatch'];
        }
        if (is_string($snapshot['ruleset_hash'] ?? null)
            && is_string($snapshot['integration_hash'] ?? null)
            && is_string($snapshot['resolved_context_profile_model'] ?? null)
            && is_string($snapshot['resolved_context_contract_hash'] ?? null)
            && is_string($snapshot['resolved_context_profile_cache_hash'] ?? null)
            && $snapshot['resolved_context_profile_cache_hash'] !== $this->resolvedProfileCacheHasher->rootCacheHash(
                $snapshot['ruleset_hash'],
                $snapshot['integration_hash'],
                $snapshot['resolved_context_profile_model'],
                $snapshot['resolved_context_contract_hash'],
                $characters,
            )
        ) {
            $errors[] = ['character_key' => null, 'reason' => 'resolved_context_profile_root_cache_hash_mismatch'];
        }

        $forbidden = $this->findForbiddenKeys($snapshot);
        foreach ($forbidden as $key) {
            $errors[] = ['character_key' => null, 'reason' => "direct_identifier_key_present:{$key}"];
        }

        if (($snapshot['action_profile_authoritative'] ?? null) !== true) {
            $warnings[] = 'action_profile_model_is_non_authoritative';
        }
        if (! $resolvedAuthoritative) {
            $warnings[] = 'resolved_context_profile_model_is_non_authoritative';
        }

        return [
            'ready' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'counts' => [
                'characters' => count($characters),
                'valid_characters' => $validCharacters,
                'invalid_characters' => count($characters) - $validCharacters,
                'errors' => count($errors),
                'warnings' => count($warnings),
            ],
        ];
    }

    /** @return list<string> */
    private function validateCharacter(
        array $row,
        ?int $expectedProfiles,
        ?int $expectedResolvedProfiles,
        string $rulesetHash,
        array $contextPlan,
        bool $resolvedAuthoritative,
    ): array {
        $errors = [];
        foreach (['participant_key', 'character_key'] as $key) {
            if (! is_string($row[$key] ?? null) || ! preg_match('/^nr[pc]2_[a-f0-9]{32}$/', $row[$key])) {
                $errors[] = "invalid_{$key}";
            }
        }
        if (($row['nation_key'] ?? null) !== null
            && (! is_string($row['nation_key']) || ! preg_match('/^nrn2_[a-f0-9]{32}$/', $row['nation_key']))) {
            $errors[] = 'invalid_nation_key';
        }

        $abilities = is_array($row['abilities'] ?? null) ? $row['abilities'] : [];
        foreach (['max_hp', 'max_sp', 'attack', 'defense', 'magic', 'spirit', 'agility', 'luck'] as $ability) {
            $value = $abilities[$ability] ?? null;
            if (! is_int($value)) {
                $errors[] = "non_integer_ability:{$ability}";

                continue;
            }
            $minimum = $ability === 'max_hp' ? 1 : 0;
            if ($value < $minimum || $value > 2_147_483_647) {
                $errors[] = "out_of_range_ability:{$ability}";
            }
        }

        $job = is_array($row['job'] ?? null) ? $row['job'] : [];
        if (! is_string($job['current_job_key'] ?? null) || trim($job['current_job_key']) === '') {
            $errors[] = 'missing_current_job_key';
        }
        if (! is_int($job['mastered_job_count'] ?? null) || $job['mastered_job_count'] < 0) {
            $errors[] = 'invalid_mastered_job_count';
        }
        if (! is_bool($job['counterplay_enabled'] ?? null)) {
            $errors[] = 'invalid_counterplay_enabled';
        }

        $killer = is_array($row['raid_killer'] ?? null) ? $row['raid_killer'] : [];
        $killerRate = $killer['damage_rate'] ?? null;
        $killerCap = $killer['damage_rate_cap'] ?? null;
        if (($killer['boss_species_key'] ?? null) !== NationRaidRules::BOSS_SPECIES_KEY) {
            $errors[] = 'invalid_raid_killer_species_key';
        }
        if (! is_bool($killer['matched'] ?? null)) {
            $errors[] = 'invalid_raid_killer_match_flag';
        }
        if (! is_int($killerRate) && ! is_float($killerRate)) {
            $errors[] = 'invalid_raid_killer_damage_rate';
        } elseif ($killerRate < 0 || $killerRate > NationRaidRules::BOSS_KILLER_DAMAGE_RATE_CAP) {
            $errors[] = 'out_of_range_raid_killer_damage_rate';
        }
        if (! is_int($killerCap) && ! is_float($killerCap)) {
            $errors[] = 'invalid_raid_killer_damage_rate_cap';
        } elseif ((float) $killerCap !== NationRaidRules::BOSS_KILLER_DAMAGE_RATE_CAP) {
            $errors[] = 'unexpected_raid_killer_damage_rate_cap';
        }
        $killerEffects = $killer['effects'] ?? null;
        if (! is_array($killerEffects)) {
            $errors[] = 'invalid_raid_killer_effects';
        } else {
            $sum = 0.0;
            foreach ($killerEffects as $effect) {
                $effectRate = is_array($effect) ? ($effect['damage_rate'] ?? null) : null;
                if (! is_array($effect)
                    || ! in_array($effect['source'] ?? null, ['innate', 'affix'], true)
                    || ($effect['species_key'] ?? null) !== NationRaidRules::BOSS_SPECIES_KEY
                    || (! is_int($effectRate) && ! is_float($effectRate))
                    || $effectRate < 0
                    || $effectRate > 1
                ) {
                    $errors[] = 'invalid_raid_killer_effect';

                    continue;
                }
                $sum += (float) $effectRate;
            }
            if ((is_int($killerRate) || is_float($killerRate))
                && abs((float) $killerRate - NationRaidRules::raidKillerDamageRate($sum)) > 0.000001
            ) {
                $errors[] = 'raid_killer_damage_rate_mismatch';
            }
        }
        if (is_bool($killer['matched'] ?? null)
            && (is_int($killerRate) || is_float($killerRate))
            && $killer['matched'] !== ((float) $killerRate > 0.0)
        ) {
            $errors[] = 'raid_killer_match_flag_mismatch';
        }

        $resistance = is_array($row['raid_resistance'] ?? null) ? $row['raid_resistance'] : [];
        $resistanceRate = $resistance['damage_reduction_rate'] ?? null;
        $resistanceCap = $resistance['damage_reduction_rate_cap'] ?? null;
        if (($resistance['boss_species_key'] ?? null) !== NationRaidRules::BOSS_SPECIES_KEY) {
            $errors[] = 'invalid_raid_resistance_species_key';
        }
        if (! is_bool($resistance['matched'] ?? null)) {
            $errors[] = 'invalid_raid_resistance_match_flag';
        }
        if (! is_int($resistanceRate) && ! is_float($resistanceRate)) {
            $errors[] = 'invalid_raid_resistance_rate';
        } elseif ($resistanceRate < 0 || $resistanceRate > 0.95) {
            $errors[] = 'out_of_range_raid_resistance_rate';
        }
        $expectedResistanceCap = NationRaidRules::ARMOR_SPECIES_RESISTANCE_RATE_CAP;
        if (! is_int($resistanceCap) && ! is_float($resistanceCap)) {
            $errors[] = 'invalid_raid_resistance_rate_cap';
        } elseif ((float) $resistanceCap !== $expectedResistanceCap) {
            $errors[] = 'unexpected_raid_resistance_rate_cap';
        }
        if ((is_int($resistanceRate) || is_float($resistanceRate))
            && (is_int($resistanceCap) || is_float($resistanceCap))
            && (float) $resistanceRate > (float) $resistanceCap
        ) {
            $errors[] = 'raid_resistance_rate_exceeds_cap';
        }
        if (is_bool($resistance['matched'] ?? null)
            && (is_int($resistanceRate) || is_float($resistanceRate))
            && $resistance['matched'] !== ((float) $resistanceRate > 0.0)
        ) {
            $errors[] = 'raid_resistance_match_flag_mismatch';
        }

        $set = $row['boss_set_exact_identities'] ?? null;
        if (! is_array($set) || count($set) !== 5) {
            $errors[] = 'invalid_boss_set';
        } elseif (array_filter($set, static fn (mixed $identity): bool => $identity !== null && ! is_string($identity)) !== []) {
            $errors[] = 'invalid_boss_set_identity';
        }

        $votes = $row['lineage_votes'] ?? null;
        if (! is_array($votes) || count($votes) !== count(array_unique($votes))) {
            $errors[] = 'invalid_lineage_votes';
        } else {
            $allowed = ['field', 'counter', 'dark', 'pierce', 'hunt', 'aim', 'guardian', 'transmute', 'break', 'command'];
            foreach ($votes as $vote) {
                if (! is_string($vote) || ! in_array($vote, $allowed, true)) {
                    $errors[] = 'invalid_lineage_vote';
                    break;
                }
            }
        }

        $activity = is_array($row['activity'] ?? null) ? $row['activity'] : [];
        if (! is_int($activity['battles_7d'] ?? null) || $activity['battles_7d'] < 1) {
            $errors[] = 'invalid_activity_battles_7d';
        }
        if (! is_array($activity['daily_battle_counts'] ?? null)
            || count($activity['daily_battle_counts']) !== 7
            || array_filter($activity['daily_battle_counts'], static fn (mixed $count): bool => ! is_int($count) || $count < 0) !== []) {
            $errors[] = 'invalid_daily_battle_counts';
        } elseif (array_sum($activity['daily_battle_counts']) !== ($activity['battles_7d'] ?? null)) {
            $errors[] = 'activity_count_mismatch';
        }
        $minuteSamples = $activity['minute_of_day_samples'] ?? null;
        if (! is_array($minuteSamples)
            || count($minuteSamples) !== ($activity['battles_7d'] ?? null)
            || array_filter($minuteSamples, static fn (mixed $minute): bool => ! is_int($minute) || $minute < 0 || $minute >= 1_440) !== []) {
            $errors[] = 'invalid_activity_minute_samples';
        } else {
            $sortedMinuteSamples = $minuteSamples;
            sort($sortedMinuteSamples, SORT_NUMERIC);
            if ($minuteSamples !== $sortedMinuteSamples) {
                $errors[] = 'activity_minute_samples_not_sorted';
            }
        }
        foreach (['observed_damage_samples', 'observed_damage_total', 'observed_damage_max'] as $metric) {
            if (! is_int($activity[$metric] ?? null) || $activity[$metric] < 0) {
                $errors[] = "invalid_activity_metric:{$metric}";
            }
        }

        $cluster = is_array($row['participation_cluster'] ?? null) ? $row['participation_cluster'] : [];
        if ($cluster !== ['days' => 7, 'daily_slot_cap' => 5, 'event_slot_cap' => 35]) {
            $errors[] = 'invalid_participation_cluster';
        }

        $profiles = $row['action_profiles'] ?? null;
        if (! is_array($profiles) || $profiles === []) {
            $errors[] = 'missing_action_profiles';
        } else {
            if ($expectedProfiles !== null && count($profiles) !== $expectedProfiles) {
                $errors[] = 'action_profile_count_mismatch';
            }
            $profileNumbers = [];
            foreach ($profiles as $profile) {
                if (! is_array($profile) || ! is_int($profile['profile_no'] ?? null)
                    || ! is_array($profile['actions'] ?? null) || count($profile['actions']) !== NationRaidRules::MAX_TURNS) {
                    $errors[] = 'invalid_action_profile_shape';

                    continue;
                }
                $profileNumbers[] = $profile['profile_no'];
                if (! is_string($profile['profile_hash'] ?? null)
                    || ! preg_match('/^[a-f0-9]{64}$/', $profile['profile_hash'])
                    || $profile['profile_hash'] !== $this->profileCacheHasher->profileHash($profile)
                ) {
                    $errors[] = 'action_profile_hash_mismatch';
                }
                if (array_column($profile['actions'], 'turn') !== range(1, NationRaidRules::MAX_TURNS)) {
                    $errors[] = 'invalid_action_turn_sequence';
                }
                foreach ($profile['actions'] as $action) {
                    if (! is_array($action)) {
                        $errors[] = 'invalid_action_snapshot';

                        continue;
                    }
                    try {
                        new NationRaidPlayerActionSnapshot(
                            turn: $action['turn'] ?? 0,
                            damageSources: $action['damage_sources'] ?? [],
                            selectedCounterplayIdentity: $action['selected_counterplay_identity'] ?? null,
                            bossDebuffKeysApplied: $action['boss_debuff_keys_applied'] ?? [],
                            counterplayHit: $action['counterplay_hit'] ?? true,
                            huntingMarkCount: $action['hunting_mark_count'] ?? 0,
                            breakMarkCount: $action['break_mark_count'] ?? 0,
                        );
                    } catch (Throwable) {
                        $errors[] = 'invalid_action_snapshot';
                    }
                    $selectedIdentity = $action['selected_counterplay_identity'] ?? null;
                    if ($selectedIdentity !== null
                        && (! is_string($selectedIdentity) || ! is_array($set) || ! in_array($selectedIdentity, $set, true))
                    ) {
                        $errors[] = 'selected_counterplay_identity_not_in_boss_set';
                    }
                }
            }
            sort($profileNumbers);
            if ($profileNumbers !== range(1, count($profiles))) {
                $errors[] = 'invalid_action_profile_numbers';
            }
            if (! is_string($row['action_profile_cache_hash'] ?? null)
                || ! preg_match('/^[a-f0-9]{64}$/', $row['action_profile_cache_hash'])
                || $row['action_profile_cache_hash'] !== $this->profileCacheHasher->characterCacheHash($profiles)
            ) {
                $errors[] = 'action_profile_character_cache_hash_mismatch';
            }
        }

        $resolvedProfiles = $row['resolved_context_profiles'] ?? null;
        if (! is_array($resolvedProfiles)) {
            $errors[] = 'invalid_resolved_context_profiles';
        } else {
            if ($resolvedAuthoritative && $resolvedProfiles === []) {
                $errors[] = 'missing_authoritative_resolved_context_profiles';
            }
            $seenContextKeys = [];
            $profilesByBaseContext = [];
            foreach ($resolvedProfiles as $profile) {
                if (! is_array($profile)) {
                    $errors[] = 'invalid_resolved_context_profile_shape';

                    continue;
                }
                foreach ($this->resolvedProfileValidator->validate(
                    $profile,
                    is_string($row['character_key'] ?? null) ? $row['character_key'] : '',
                    $rulesetHash,
                ) as $reason) {
                    $errors[] = $reason;
                }
                try {
                    $context = NationRaidResolvedProfileContext::fromArray(
                        is_array($profile['context'] ?? null) ? $profile['context'] : [],
                        is_string($row['character_key'] ?? null) ? $row['character_key'] : '',
                    );
                } catch (Throwable) {
                    continue;
                }
                if (isset($seenContextKeys[$context->key()])) {
                    $errors[] = 'duplicate_resolved_context_key';
                }
                $seenContextKeys[$context->key()] = true;
                $profilesByBaseContext[$context->baseKey()][] = $context->profileNo;
            }
            $actualOrder = array_map(
                static fn (array $profile): string => (string) ($profile['context_key'] ?? ''),
                array_filter($resolvedProfiles, 'is_array'),
            );
            $sortedOrder = $actualOrder;
            sort($sortedOrder, SORT_STRING);
            if ($actualOrder !== $sortedOrder) {
                $errors[] = 'resolved_context_profiles_not_sorted';
            }

            $planKeys = array_fill_keys(array_map(fn (array $context): string => $this->baseContextKey($context), $contextPlan), true);
            foreach ($profilesByBaseContext as $baseKey => $profileNumbers) {
                sort($profileNumbers);
                if (! isset($planKeys[$baseKey])) {
                    $errors[] = 'resolved_context_profile_outside_plan';
                }
                if ($expectedResolvedProfiles !== null && $profileNumbers !== range(1, $expectedResolvedProfiles)) {
                    $errors[] = 'resolved_context_profile_number_gap';
                }
            }
            if ($resolvedAuthoritative) {
                foreach (array_keys($planKeys) as $baseKey) {
                    if (! isset($profilesByBaseContext[$baseKey])) {
                        $errors[] = 'resolved_context_plan_coverage_missing';
                        break;
                    }
                }
            }
            if (! is_string($row['resolved_context_profile_cache_hash'] ?? null)
                || ! preg_match('/^[a-f0-9]{64}$/', $row['resolved_context_profile_cache_hash'])
                || $row['resolved_context_profile_cache_hash'] !== $this->resolvedProfileCacheHasher
                    ->characterCacheHash($resolvedProfiles)
            ) {
                $errors[] = 'resolved_context_profile_character_cache_hash_mismatch';
            }
        }

        return array_values(array_unique($errors));
    }

    /** @param array<string, mixed> $context */
    private function baseContextKey(array $context): string
    {
        return (new NationRaidResolvedProfileContext(
            stage: $context['stage'],
            startingForm: $context['starting_form'],
            strategy: $context['strategy'],
            dominantLineage: $context['dominant_lineage'],
            profileNo: 1,
            sortieSeed: 1,
        ))->baseKey();
    }

    /** @return list<string> */
    private function findForbiddenKeys(array $payload): array
    {
        $forbidden = [
            'name',
            'email',
            'username',
            'display_name',
            'account_name',
            'character_name',
            'nation_name',
            'google_id',
            'freeze_reason',
            'user_id',
            'account_id',
            'character_id',
            'nation_id',
        ];
        $found = [];
        $walk = function (array $values) use (&$walk, &$found, $forbidden): void {
            foreach ($values as $key => $value) {
                if (is_string($key) && in_array(strtolower($key), $forbidden, true)) {
                    $found[$key] = true;
                }
                if (is_array($value)) {
                    $walk($value);
                }
            }
        };
        $walk($payload);

        return array_keys($found);
    }

    private function validActiveWindow(array $snapshot): bool
    {
        $window = is_array($snapshot['active_window'] ?? null) ? $snapshot['active_window'] : [];
        if (($window['days'] ?? null) !== 7
            || ! is_string($window['from'] ?? null)
            || ! is_string($window['to'] ?? null)
            || ! is_string($snapshot['extracted_at'] ?? null)) {
            return false;
        }

        try {
            $from = CarbonImmutable::parse($window['from']);
            $to = CarbonImmutable::parse($window['to']);
            $extractedAt = CarbonImmutable::parse($snapshot['extracted_at']);
        } catch (Throwable) {
            return false;
        }

        return $to->equalTo($extractedAt) && $from->addDays(7)->equalTo($to);
    }
}
