<?php

namespace App\Services\Nation\Raid\Simulation;

use JsonException;

/**
 * Phase 2実行の環境依存な所要時間・容量を、決定的なsimulation成果物から分離して記録する。
 */
final class NationRaidSimulationOperationalMetricsBuilder
{
    public const VERSION = 'nation-raid-phase2-operational-metrics-v1';

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, string|null>  $artifactPaths
     * @param  array<string, int|float|null>  $timings
     * @param  array<string, mixed>  $execution
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    public function build(
        array $snapshot,
        array $artifactPaths,
        array $timings,
        array $execution,
    ): array {
        $characters = is_array($snapshot['characters'] ?? null)
            ? array_values($snapshot['characters'])
            : [];
        $artifactBytes = $this->artifactBytes($artifactPaths);
        $resolvedProfiles = $this->profilePayloadStats($characters, 'resolved_context_profiles');

        return [
            'schema_version' => self::VERSION,
            'execution' => [
                'outcome' => (string) ($execution['outcome'] ?? 'unknown'),
                'snapshot_source' => (string) ($execution['snapshot_source'] ?? 'unknown'),
                'resolved_context_plan_provided' => (bool) ($execution['resolved_context_plan_provided'] ?? false),
                'snapshot_only' => (bool) ($execution['snapshot_only'] ?? false),
                'seed_count' => $this->nullableNonNegativeInt($execution['seed_count'] ?? null),
                'seed_start' => $this->nullableNonNegativeInt($execution['seed_start'] ?? null),
                'strategy_mode' => (string) ($execution['strategy_mode'] ?? 'unknown'),
                'reference_profile_allowed' => (bool) ($execution['reference_profile_allowed'] ?? false),
                'balance_gate_authoritative' => (bool) ($execution['balance_gate_authoritative'] ?? false),
            ],
            'fingerprints' => [
                'snapshot_schema_version' => $snapshot['schema_version'] ?? null,
                'ruleset_hash' => $snapshot['ruleset_hash'] ?? null,
                'integration_hash' => $snapshot['integration_hash'] ?? null,
                'action_profile_cache_hash' => $snapshot['action_profile_cache_hash'] ?? null,
                'resolved_context_profile_cache_hash' => $snapshot['resolved_context_profile_cache_hash'] ?? null,
                'resolved_context_plan_hash' => $snapshot['resolved_context_plan_hash'] ?? null,
            ],
            'timing_ms' => $this->normalizedTimings($timings),
            'capacity' => [
                'characters' => count($characters),
                'planned_contexts' => is_array($snapshot['resolved_context_plan'] ?? null)
                    ? count($snapshot['resolved_context_plan'])
                    : 0,
                'profiles_per_context' => $this->nullableNonNegativeInt(
                    $snapshot['resolved_context_profiles_per_context'] ?? null,
                ),
                'action_profile_cache' => $this->profilePayloadStats($characters, 'action_profiles'),
                'resolved_context_profile_cache' => $resolvedProfiles,
                'artifact_file_bytes' => $artifactBytes,
                'artifact_total_bytes_before_operational_report' => array_sum($artifactBytes),
                // Laravel bootstrapを含むprocess全体の高水位。cache単体のheap量ではない。
                'php_memory_usage_bytes_at_report' => memory_get_usage(true),
                'php_peak_memory_usage_bytes_at_report' => memory_get_peak_usage(true),
            ],
            'measurement_scope' => [
                'snapshot_load_or_build_ms_includes' => [
                    'database_extraction_when_not_reusing_snapshot',
                    'final_stat_calculation',
                    'legacy_action_profile_generation',
                    'resolved_context_profile_generation',
                    'snapshot_hashing',
                ],
                'resolved_cache_generation_time_isolated' => false,
                'resolved_cache_generation_time_method' => 'compare_planless_and_candidate_plan_runs_on_the_same_population',
                'profile_payload_bytes_format' => 'compact_json_per_character_without_php_object_overhead',
                'artifact_file_bytes_format' => 'exact_on_disk_bytes_before_operational_metrics_json',
                'php_memory_scope' => 'whole_command_process_including_framework_bootstrap',
            ],
        ];
    }

    /**
     * @param  list<mixed>  $characters
     * @return array{profiles:int,characters_with_profiles:int,compact_json_bytes:int,average_compact_json_bytes_per_profile:?float,max_profiles_per_character:int,max_compact_json_bytes_per_character:int}
     *
     * @throws JsonException
     */
    private function profilePayloadStats(array $characters, string $field): array
    {
        $profileCount = 0;
        $charactersWithProfiles = 0;
        $compactBytes = 0;
        $maxProfiles = 0;
        $maxBytes = 0;

        foreach ($characters as $character) {
            $profiles = is_array($character) && is_array($character[$field] ?? null)
                ? array_values($character[$field])
                : [];
            $count = count($profiles);
            $profileCount += $count;
            $maxProfiles = max($maxProfiles, $count);
            if ($count === 0) {
                continue;
            }

            $charactersWithProfiles++;
            $bytes = strlen(json_encode(
                $profiles,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));
            $compactBytes += $bytes;
            $maxBytes = max($maxBytes, $bytes);
        }

        return [
            'profiles' => $profileCount,
            'characters_with_profiles' => $charactersWithProfiles,
            'compact_json_bytes' => $compactBytes,
            'average_compact_json_bytes_per_profile' => $profileCount > 0
                ? round($compactBytes / $profileCount, 3)
                : null,
            'max_profiles_per_character' => $maxProfiles,
            'max_compact_json_bytes_per_character' => $maxBytes,
        ];
    }

    /** @param array<string, string|null> $paths @return array<string, int> */
    private function artifactBytes(array $paths): array
    {
        $bytes = [];
        foreach ($paths as $key => $path) {
            if ($key === 'directory' || ! is_string($path) || ! is_file($path)) {
                continue;
            }
            $size = filesize($path);
            if ($size !== false) {
                $bytes[$key] = $size;
            }
        }
        ksort($bytes, SORT_STRING);

        return $bytes;
    }

    /** @param array<string, int|float|null> $timings @return array<string, float|null> */
    private function normalizedTimings(array $timings): array
    {
        $normalized = [];
        foreach ($timings as $key => $value) {
            $normalized[$key] = (is_int($value) || is_float($value)) && $value >= 0
                ? round((float) $value, 3)
                : null;
        }

        return $normalized;
    }

    private function nullableNonNegativeInt(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }
}
