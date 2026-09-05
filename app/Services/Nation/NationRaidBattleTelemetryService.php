<?php

namespace App\Services\Nation;

use App\Models\NationRaidBattleTelemetryLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * 国家対抗レイドの出撃テレメトリwriter。
 *
 * 戦闘終了時に1回だけ呼ぶ。計測は副次責務であり、table未作成・不正な計測値・
 * DB例外があっても戦闘結果、共有HP、報酬を失敗させない。
 */
final class NationRaidBattleTelemetryService
{
    public const SCHEMA_VERSION = '1.1';

    /** @var list<string> */
    private const LINEAGES = [
        'field', 'counter', 'eclipse', 'pierce', 'hunt',
        'aim', 'guard', 'transmute', 'break', 'command',
    ];

    /** @var list<string> */
    private const RESULT_STATUSES = ['resolved', 'aborted', 'refunded'];

    /** @var list<string> */
    private const DAMAGE_SOURCE_KEYS = [
        'normal', 'job_art_direct', 'direct_unclassified', 'dot', 'counter', 'eclipse_backlash', 'percent', 'companion', 'other',
    ];

    /** @var list<string> */
    private const COUNTERPLAY_KEYS = [
        'telegraphs_seen',
        'guards_20',
        'guards_35',
        'guards_50',
        'hunt_delays',
        'command_delays',
        'sp_denials',
        'effect_suppressions',
        'ultimate_casts',
        'ultimate_fallbacks',
        'adaptive_casts',
        'adaptive_delays',
        'responses_selected', 'responses_applied', 'preparations_destroyed',
        'aim_sp_pressure', 'transmute_resource_slow', 'turn_18_delay', 'turn_20_delay', 'denial_overlap',
    ];

    private ?bool $tableExists = null;

    /**
     * @param  array<string, mixed>  $data
     */
    public function record(array $data): ?NationRaidBattleTelemetryLog
    {
        if (! $this->tableExists()) {
            return null;
        }

        try {
            $eventKey = $this->limitedString($data['event_key'] ?? null, 64);
            $battleToken = trim((string) ($data['battle_token'] ?? ''));
            $status = trim((string) ($data['result_status'] ?? ''));

            if ($eventKey === '' || $battleToken === '' || ! in_array($status, self::RESULT_STATUSES, true)) {
                report(new \InvalidArgumentException('国家レイド計測にevent_key、battle_token、正しいresult_statusが必要です。'));

                return null;
            }

            $rawTurns = is_array($data['turns'] ?? null) ? $data['turns'] : [];
            $turns = $this->normalizeTurns($rawTurns);
            $reportedTurnCount = $this->nonNegativeInt($data['turn_count'] ?? count($turns));
            $turnCount = min(20, $reportedTurnCount);
            $qualityFlags = $this->normalizeQualityFlags($data['quality_flags'] ?? []);

            if ($reportedTurnCount > 20) {
                $qualityFlags[] = 'turn_count_clamped';
            }
            if (count($rawTurns) > 20) {
                $qualityFlags[] = 'turn_detail_truncated';
            }
            if ($status === 'resolved' && $turnCount > 0 && count($turns) !== $turnCount) {
                $qualityFlags[] = 'turn_detail_count_mismatch';
            }
            $turnNumbers = array_column($turns, 'turn');
            if (count($turnNumbers) !== count(array_unique($turnNumbers))) {
                $qualityFlags[] = 'duplicate_turn_number';
            }
            $expectedTurnNumbers = $turnCount > 0 ? range(1, $turnCount) : [];
            if ($status === 'resolved' && $turnNumbers !== $expectedTurnNumbers) {
                $qualityFlags[] = 'non_contiguous_turn_numbers';
            }
            if (trim((string) ($data['ruleset_version'] ?? '')) === '') {
                $qualityFlags[] = 'ruleset_version_missing';
            }

            $hasTurnDetails = $turns !== [];
            $reachedTurnTwenty = $turnCount >= 20
                && (! $hasTurnDetails || $turnNumbers === range(1, 20));
            if (array_key_exists('reached_turn_twenty', $data)
                && (bool) $data['reached_turn_twenty'] !== $reachedTurnTwenty
            ) {
                $qualityFlags[] = 'reached_turn_twenty_mismatch';
            }

            $providedMaxActionDamage = $this->nonNegativeInt($data['max_action_damage'] ?? 0);
            $derivedMaxActionDamage = $this->maxActionDamageFromTurns($turns);
            $maxActionDamage = $derivedMaxActionDamage['available']
                ? $derivedMaxActionDamage['damage']
                : $providedMaxActionDamage;
            if ($derivedMaxActionDamage['available'] && $providedMaxActionDamage !== $derivedMaxActionDamage['damage']) {
                $qualityFlags[] = 'max_action_damage_mismatch';
            }

            $rawLoadoutLineages = is_array($data['loadout_lineages'] ?? null)
                ? array_values(array_filter(
                    $data['loadout_lineages'],
                    static fn (mixed $value): bool => is_scalar($value) || $value instanceof \Stringable,
                ))
                : [];
            $loadoutLineages = $this->normalizeLineages($rawLoadoutLineages);
            $unknownLineages = array_values(array_diff(
                array_unique(array_map('strval', $rawLoadoutLineages)),
                self::LINEAGES,
            ));
            if ($unknownLineages !== []) {
                $qualityFlags[] = 'unknown_loadout_lineage';
            }

            $adaptiveLineage = $this->nullableLineage($data['adaptive_lineage'] ?? null);
            if (($data['adaptive_lineage'] ?? null) !== null && $adaptiveLineage === null) {
                $qualityFlags[] = 'unknown_adaptive_lineage';
            }

            $nationId = $this->nullablePositiveInt($data['nation_id'] ?? null);
            $isNationEligible = $nationId !== null && (bool) ($data['is_nation_eligible'] ?? false);
            if ($nationId === null && (bool) ($data['is_nation_eligible'] ?? false)) {
                $qualityFlags[] = 'nation_eligibility_without_nation';
            }

            $attributes = [
                'event_key' => $eventKey,
                'telemetry_schema_version' => self::SCHEMA_VERSION,
                'ruleset_version' => $this->limitedString($data['ruleset_version'] ?? 'unknown', 32),
                'raid_day' => $this->boundedInt($data['raid_day'] ?? 1, 1, 7),
                'day_sortie_no' => $this->boundedInt($data['day_sortie_no'] ?? 1, 1, 4_294_967_295),
                'event_sortie_no' => $this->boundedInt($data['event_sortie_no'] ?? 1, 1, 4_294_967_295),
                'boss_cycle_no' => max(1, $this->nonNegativeInt($data['boss_cycle_no'] ?? 1)),
                'character_id' => $this->nullablePositiveInt($data['character_id'] ?? null),
                'nation_id' => $nationId,
                'is_nation_eligible' => $isNationEligible,
                'nation_active_count' => $this->nonNegativeInt($data['nation_active_count'] ?? 0),
                'player_level' => $this->boundedInt($data['player_level'] ?? 1, 1, 255),
                'player_job_id' => $this->nullablePositiveInt($data['player_job_id'] ?? null),
                'player_power' => $this->nullableNonNegativeInt($data['player_power'] ?? null),
                'boss_phase' => $this->limitedString($data['boss_phase'] ?? 'unknown', 32),
                'adaptive_lineage' => $adaptiveLineage,
                'result_status' => $status,
                'end_reason' => $this->limitedString($data['end_reason'] ?? 'unknown', 32),
                'turn_count' => $turnCount,
                'reached_turn_twenty' => $reachedTurnTwenty,
                'boss_hp_before' => $this->nonNegativeInt($data['boss_hp_before'] ?? 0),
                'boss_hp_after' => $this->nonNegativeInt($data['boss_hp_after'] ?? 0),
                'calculated_damage_total' => $this->nonNegativeInt($data['calculated_damage_total'] ?? 0),
                'applied_damage_total' => $this->nonNegativeInt($data['applied_damage_total'] ?? 0),
                'max_action_damage' => $maxActionDamage,
                'damage_taken_total' => $this->nonNegativeInt($data['damage_taken_total'] ?? 0),
                'healing_total' => $this->nonNegativeInt($data['healing_total'] ?? 0),
                'player_hp_ratio_end' => $this->nullableRatio($data['player_hp_ratio_end'] ?? null),
                'duration_ms' => $this->nullableNonNegativeInt($data['duration_ms'] ?? null),
                'battle_started_at' => $this->nullableDateTime($data['battle_started_at'] ?? null),
                'battle_resolved_at' => $this->nullableDateTime($data['battle_resolved_at'] ?? null),
                'loadout_lineages' => $loadoutLineages,
                'loadout_snapshot' => $this->normalizeLoadout($data['loadout_snapshot'] ?? []),
                'damage_by_source' => $this->normalizeMetricMap($data['damage_by_source'] ?? [], self::DAMAGE_SOURCE_KEYS),
                'counterplay_metrics' => $this->normalizeMetricMap($data['counterplay_metrics'] ?? [], self::COUNTERPLAY_KEYS),
                'turns' => $turns,
                'event_snapshot' => $this->normalizeEventSnapshot($data['event_snapshot'] ?? []),
                'player_snapshot' => $this->normalizePlayerSnapshot($data['player_snapshot'] ?? []),
                'quality_flags' => array_values(array_unique($qualityFlags)),
            ];

            $tokenHash = hash('sha256', $battleToken);

            try {
                return NationRaidBattleTelemetryLog::query()->firstOrCreate(
                    ['battle_token_hash' => $tokenHash],
                    $attributes,
                );
            } catch (QueryException $e) {
                $existing = NationRaidBattleTelemetryLog::query()
                    ->where('battle_token_hash', $tokenHash)
                    ->first();
                if ($existing !== null) {
                    return $existing;
                }

                throw $e;
            }
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /** @return list<string> */
    private function normalizeLineages(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $item): string => trim((string) $item), array_slice($value, 0, 5)),
            static fn (string $lineage): bool => in_array($lineage, self::LINEAGES, true),
        )));
    }

    private function nullableLineage(mixed $value): ?string
    {
        $lineage = trim((string) ($value ?? ''));

        return in_array($lineage, self::LINEAGES, true) ? $lineage : null;
    }

    /** @return list<array<string, mixed>> */
    private function normalizeLoadout(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach (array_slice($value, 0, 5) as $slot) {
            if (! is_array($slot)) {
                continue;
            }
            $normalized[] = [
                'slot_no' => $this->boundedInt($slot['slot_no'] ?? count($normalized) + 1, 1, 5),
                'skill_id' => $this->nullablePositiveInt($slot['skill_id'] ?? null),
                'job_id' => $this->nullablePositiveInt($slot['job_id'] ?? null),
                'rank' => $this->nullableNonNegativeInt($slot['rank'] ?? null),
                'name' => $this->limitedString($slot['name'] ?? '', 80),
                'lineage' => $this->nullableLineage($slot['lineage'] ?? null),
                'condition' => $this->limitedString($slot['condition'] ?? '', 48),
            ];
        }

        return $normalized;
    }

    /** @param list<string> $allowedKeys @return array<string, int> */
    private function normalizeMetricMap(mixed $value, array $allowedKeys): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($allowedKeys as $key) {
            if (array_key_exists($key, $value)) {
                $normalized[$key] = $this->nonNegativeInt($value[$key]);
            }
        }

        return $normalized;
    }

    /** @return list<array<string, mixed>> */
    private function normalizeTurns(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach (array_slice($value, 0, 20) as $index => $turn) {
            if (! is_array($turn)) {
                continue;
            }
            $normalized[] = [
                'turn' => $this->boundedInt($turn['turn'] ?? $index + 1, 1, 20),
                'boss_phase' => $this->limitedString($turn['boss_phase'] ?? 'unknown', 32),
                'player_action' => $this->normalizePlayerAction($turn['player_action'] ?? []),
                'boss_action' => $this->normalizeBossAction($turn['boss_action'] ?? []),
                'player_hp_before' => $this->nullableNonNegativeInt($turn['player_hp_before'] ?? null),
                'player_hp_after' => $this->nonNegativeInt($turn['player_hp_after'] ?? 0),
                'player_sp_before' => $this->nullableNonNegativeInt($turn['player_sp_before'] ?? null),
                'player_sp_after' => $this->nonNegativeInt($turn['player_sp_after'] ?? 0),
                'boss_sp_before' => $this->nullableNonNegativeInt($turn['boss_sp_before'] ?? null),
                'boss_sp_after' => $this->nullableNonNegativeInt($turn['boss_sp_after'] ?? null),
                'boss_hp_before' => $this->nonNegativeInt($turn['boss_hp_before'] ?? 0),
                'boss_hp_after' => $this->nonNegativeInt($turn['boss_hp_after'] ?? 0),
                'player_self_damage' => $this->nullableNonNegativeInt($turn['player_self_damage'] ?? null),
            ];
        }

        usort($normalized, static fn (array $left, array $right): int => $left['turn'] <=> $right['turn']);

        return $normalized;
    }

    /** @param list<array<string, mixed>> $turns @return array{available: bool, damage: int} */
    private function maxActionDamageFromTurns(array $turns): array
    {
        $maximum = 0;
        $available = false;
        $actionBoundSources = ['normal', 'job_art_direct', 'direct_unclassified', 'percent', 'companion', 'other'];

        foreach ($turns as $turn) {
            $action = is_array($turn['player_action'] ?? null) ? $turn['player_action'] : [];
            $sources = is_array($action['damage_by_source'] ?? null) ? $action['damage_by_source'] : [];
            if ($sources !== [] || array_key_exists('damage_total', $action)) {
                $available = true;
            }
            $candidate = $sources === []
                ? $this->nonNegativeInt($action['damage_total'] ?? 0)
                : array_sum(array_map(
                    fn (string $key): int => $this->nonNegativeInt($sources[$key] ?? 0),
                    $actionBoundSources,
                ));
            $maximum = max($maximum, $candidate);
        }

        return ['available' => $available, 'damage' => $maximum];
    }

    /** @return array<string, mixed> */
    private function normalizePlayerAction(mixed $value): array
    {
        $action = is_array($value) ? $value : [];

        return [
            'action_type' => $this->limitedString($action['action_type'] ?? 'unknown', 32),
            'action_key' => $this->limitedString($action['action_key'] ?? 'unknown', 64),
            'skill_id' => $this->nullablePositiveInt($action['skill_id'] ?? null),
            'skill_name' => $this->limitedString($action['skill_name'] ?? '', 80),
            'lineage' => $this->nullableLineage($action['lineage'] ?? null),
            'hit_count' => $this->nullableNonNegativeInt($action['hit_count'] ?? null),
            'critical_count' => $this->nullableNonNegativeInt($action['critical_count'] ?? null),
            'miss_count' => $this->nullableNonNegativeInt($action['miss_count'] ?? null),
            'damage_total' => $this->nonNegativeInt($action['damage_total'] ?? 0),
            'damage_by_source' => $this->normalizeMetricMap($action['damage_by_source'] ?? [], self::DAMAGE_SOURCE_KEYS),
            'healing' => $this->nullableNonNegativeInt($action['healing'] ?? null),
            'sp_spent' => $this->nullableNonNegativeInt($action['sp_spent'] ?? null),
        ];
    }

    /** @return array<string, mixed> */
    private function normalizeBossAction(mixed $value): array
    {
        $action = is_array($value) ? $value : [];

        return [
            'action_key' => $this->limitedString($action['action_key'] ?? 'none', 64),
            'action_name' => $this->limitedString($action['action_name'] ?? '', 80),
            'lineage' => $this->nullableLineage($action['lineage'] ?? null),
            'telegraphed' => (bool) ($action['telegraphed'] ?? false),
            'response' => $this->limitedString($action['response'] ?? 'none', 48),
            'response_applied' => (bool) ($action['response_applied'] ?? false),
            'outcome' => $this->limitedString($action['outcome'] ?? 'none', 48),
            'observation_reason' => $this->limitedString($action['observation_reason'] ?? '', 48),
            'hit_count' => $this->nonNegativeInt($action['hit_count'] ?? 0),
            'damage_before_cap' => $this->nullableNonNegativeInt($action['damage_before_cap'] ?? null),
            'damage_after_cap' => $this->nullableNonNegativeInt($action['damage_after_cap'] ?? null),
            'damage_cap' => $this->nullableNonNegativeInt($action['damage_cap'] ?? null),
            'actual_hp_loss' => $this->nullableNonNegativeInt($action['actual_hp_loss'] ?? null),
            'damage_final' => $this->nonNegativeInt($action['damage_final'] ?? 0),
            'status_keys' => $this->normalizeStringList($action['status_keys'] ?? [], 10, 48),
        ];
    }

    /** @return array<string, mixed> */
    private function normalizeEventSnapshot(mixed $value): array
    {
        $snapshot = is_array($value) ? $value : [];
        $keys = [
            'boss_name', 'boss_max_hp', 'boss_attack', 'boss_defense', 'boss_magic',
            'boss_spirit', 'boss_agility', 'boss_luck', 'max_turns', 'attempts_per_day',
            'duration_days', 'stamina_cost', 'valid_participation_sorties', 'reward_thresholds',
            'turn_stages', 'phase_config', 'adaptive_mapping', 'ruleset_hash',
            'measurement_contract', 'stage_no', 'echo_no', 'cycle_kind', 'strategy', 'boss_species_key',
            'killer_raw_rate', 'killer_effective_rate', 'killer_rate_cap', 'killer_rate_multiplier', 'armor_resistance_rate',
            'coordination_unique_count', 'coordination_rate', 'coordination_damage', 'nation_damage',
            'turn_hp_basis', 't20_starting_sp', 'ultimate_denial_reasons', 'reservation_failures',
            'settlement_cycle_after', 'settlement_hp_after',
        ];

        return $this->pickSafeJson($snapshot, $keys);
    }

    /** @return array<string, mixed> */
    private function normalizePlayerSnapshot(mixed $value): array
    {
        $snapshot = is_array($value) ? $value : [];
        $keys = [
            'level', 'job_id', 'power', 'max_hp', 'max_sp', 'attack', 'defense',
            'magic', 'spirit', 'agility', 'luck', 'loadout_fingerprint',
        ];

        return $this->pickSafeJson($snapshot, $keys);
    }

    /** @param array<string, mixed> $source @param list<string> $keys @return array<string, mixed> */
    private function pickSafeJson(array $source, array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $source)) {
                $result[$key] = $this->safeJsonValue($source[$key], 0);
            }
        }

        return $result;
    }

    private function safeJsonValue(mixed $value, int $depth): mixed
    {
        if ($depth >= 4) {
            return null;
        }
        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }
        if (is_string($value)) {
            return $this->limitedString($value, 120);
        }
        if (! is_array($value)) {
            return null;
        }

        $result = [];
        foreach (array_slice($value, 0, 60, true) as $key => $item) {
            $safeKey = is_int($key) ? $key : $this->limitedString($key, 48);
            $result[$safeKey] = $this->safeJsonValue($item, $depth + 1);
        }

        return $result;
    }

    /** @return list<string> */
    private function normalizeQualityFlags(mixed $value): array
    {
        return $this->normalizeStringList($value, 20, 64);
    }

    /** @return list<string> */
    private function normalizeStringList(mixed $value, int $limit, int $length): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $item): string => $this->limitedString($item, $length),
            array_slice($value, 0, $limit),
        ))));
    }

    private function nullableDateTime(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value);
    }

    private function limitedString(mixed $value, int $length): string
    {
        $string = preg_replace('/[\x00-\x1F\x7F]/u', ' ', trim((string) ($value ?? ''))) ?? '';

        return Str::limit($string, $length, '');
    }

    private function boundedInt(mixed $value, int $minimum, int $maximum): int
    {
        return max($minimum, min($maximum, (int) $value));
    }

    private function nonNegativeInt(mixed $value): int
    {
        return max(0, (int) $value);
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        $int = (int) ($value ?? 0);

        return $int > 0 ? $int : null;
    }

    private function nullableNonNegativeInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->nonNegativeInt($value);
    }

    private function nullableRatio(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return max(0.0, min(1.0, (float) $value));
    }

    private function tableExists(): bool
    {
        if ($this->tableExists !== null) {
            return $this->tableExists;
        }

        try {
            return $this->tableExists = Schema::hasTable('nation_raid_battle_telemetry');
        } catch (\Throwable) {
            return $this->tableExists = false;
        }
    }
}
