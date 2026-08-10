<?php

namespace App\Services;

use App\Models\Skill;

/**
 * v2を有効化できる現在職と、継承時にも信頼できるRank1/5/9 profileの正本。
 *
 * 現在職対応とArtProfileは分離する。英雄・伝説・神話は系譜／継承profileを
 * 解決できても、CURRENT_JOB_TIERSに無い限り現在職v2を起動しない。
 */
class JobArtV2PrototypeCatalog
{
    /** @var array<int, string> */
    private const CURRENT_JOB_TIERS = [
        24 => 'intermediate',

        27 => 'advanced', 28 => 'advanced', 29 => 'advanced', 30 => 'advanced',
        31 => 'advanced', 32 => 'advanced', 33 => 'advanced', 34 => 'advanced',
        35 => 'advanced', 36 => 'advanced', 37 => 'advanced', 38 => 'advanced',
        44 => 'advanced', 45 => 'advanced', 46 => 'advanced', 47 => 'advanced',
        48 => 'advanced', 49 => 'advanced',

        50 => 'super', 51 => 'super', 52 => 'super', 53 => 'super', 54 => 'super',
        55 => 'super', 56 => 'super', 57 => 'super', 58 => 'super', 59 => 'super',

        60 => 'crown', 61 => 'crown', 62 => 'crown', 63 => 'crown',
        64 => 'crown', 65 => 'crown', 66 => 'crown', 67 => 'crown',
        68 => 'crown', 69 => 'crown',
        85 => 'myth',
    ];

    /** @var array<string, array<string, int|string>> */
    private const LINEAGE_PROFILES = [
        'field' => ['resource_key' => 'star_mark', 'resource_name' => '星印', 'resource_max_points' => 12, 'normal_attack_hit_gain_points' => 1],
        'counter' => ['resource_key' => 'sword_momentum', 'resource_name' => '剣勢', 'resource_max_points' => 12, 'normal_attack_hit_gain_points' => 1, 'physical_attack_received_gain_points' => 1, 'parry_success_gain_points' => 1],
        'eclipse' => ['resource_key' => 'eclipse', 'resource_name' => '冥蝕', 'resource_max_points' => 12, 'self_damage_gain_points' => 2, 'normal_attack_hit_gain_points' => 1],
        'pierce' => ['resource_key' => 'dragon_force', 'resource_name' => '竜気', 'resource_max_points' => 12, 'normal_attack_hit_gain_points' => 1],
        'hunt' => ['resource_key' => 'hunt', 'resource_name' => '狩猟印', 'resource_max_points' => 12, 'normal_attack_hit_gain_points' => 1],
        'aim' => ['resource_key' => 'aim', 'resource_name' => '照準', 'resource_max_points' => 12, 'normal_attack_hit_gain_points' => 1, 'normal_attack_miss_gain_points' => 2],
        'guard' => ['resource_key' => 'holy_guard', 'resource_name' => '聖護', 'resource_max_points' => 12, 'normal_attack_hit_gain_points' => 1, 'damage_mitigated_gain_points' => 1, 'cleanse_success_gain_points' => 1],
        'transmute' => ['resource_key' => 'catalyst', 'resource_name' => '触媒', 'resource_max_points' => 12, 'normal_attack_hit_gain_points' => 1],
        'break' => ['resource_key' => 'break', 'resource_name' => '崩し', 'resource_max_points' => 12, 'normal_attack_hit_gain_points' => 1],
        'command' => ['resource_key' => 'command_points', 'resource_name' => '指揮点', 'resource_max_points' => 12, 'normal_attack_hit_gain_points' => 4, 'non_job_art_action_gain_points' => 1],
    ];

    /** @var array<int, array<string, int|string>> */
    private const RANK_PROFILES = [
        1 => ['resource_role' => 'producer', 'resource_gain_points' => 4, 'resource_cost_points' => 0, 'minimum_resource_points' => 0],
        5 => ['resource_role' => 'consumer', 'resource_gain_points' => 0, 'resource_cost_points' => 4, 'minimum_resource_points' => 4],
        9 => ['resource_role' => 'finisher', 'resource_gain_points' => 0, 'resource_cost_points' => 12, 'minimum_resource_points' => 12],
    ];

    /**
     * 凍結済みの個別差分だけを置く。存在しない上級／超級効果はmaster fallback。
     *
     * @var array<int, array<int, array<string, int|float|string|bool>>>
     */
    private const ART_OVERRIDES = [
        6 => [
            1 => ['field_operation' => 'deploy', 'field_selection_mode' => 'fixed', 'field_key' => 'star_light'],
        ],
        23 => [
            1 => ['field_operation' => 'deploy', 'field_selection_mode' => 'fixed', 'field_key' => 'melody'],
            5 => [
                'resource_role' => 'neutral',
                'resource_gain_points' => 0,
                'resource_cost_points' => 0,
                'minimum_resource_points' => 0,
                'requires_trusted_field' => true,
                'field_operation' => 'extend',
                'field_extend_rounds' => 2,
            ],
        ],
        24 => [
            1 => ['field_operation' => 'deploy', 'field_selection_mode' => 'fixed', 'field_key' => 'sanctuary'],
            5 => ['field_operation' => 'none'],
            9 => ['field_operation' => 'none'],
        ],
        // 29は場の選択／相手場上書きUIが未凍結のためmaster-effect fallback。
        32 => [
            5 => ['penetration_type' => 'physical_def', 'penetration_rate' => 0.30],
            9 => ['penetration_type' => 'physical_def', 'penetration_rate' => 0.50],
        ],
        45 => [
            5 => ['penetration_type' => 'physical_def', 'penetration_rate' => 0.25],
            9 => ['penetration_type' => 'physical_def', 'penetration_rate' => 0.40],
        ],
        46 => [
            1 => ['field_operation' => 'deploy', 'field_selection_mode' => 'fixed', 'field_key' => 'melody'],
            5 => ['resource_role' => 'neutral', 'resource_gain_points' => 0, 'resource_cost_points' => 0, 'minimum_resource_points' => 0, 'field_operation' => 'none'],
            9 => ['field_operation' => 'none'],
        ],
        52 => [
            5 => ['penetration_type' => 'physical_def', 'penetration_rate' => 0.35],
            9 => ['penetration_type' => 'physical_def', 'penetration_rate' => 0.50],
        ],
        53 => [
            1 => ['field_operation' => 'deploy', 'field_selection_mode' => 'fixed', 'field_key' => 'star_light'],
            5 => ['field_operation' => 'extend', 'field_extend_rounds' => 1],
            9 => ['field_operation' => 'none'],
        ],
        60 => [
            1 => ['counter_stance_rounds' => 2, 'parry_rate' => 0.20],
        ],
        61 => [
            1 => ['resource_gain_event' => 'job_art_hit'],
        ],
        62 => [
            5 => ['penetration_type' => 'physical_def', 'penetration_rate' => 0.35],
            9 => ['penetration_type' => 'physical_def', 'penetration_rate' => 0.50],
        ],
        63 => [
            1 => [
                'field_operation' => 'deploy',
                'field_selection_mode' => 'next_cycle',
                'resource_gain_on_field_overwrite_points' => 2,
            ],
            5 => [
                'field_operation' => 'echo_previous_overwritten',
                'field_echo_rounds' => 1,
            ],
            9 => [
                'field_operation' => 'none',
                'current_job_only' => true,
                'field_overwrite_power_requires_primary' => true,
                'field_overwrite_power_multiplier_1_2' => 1.05,
                'field_overwrite_power_multiplier_3_4' => 1.10,
                'field_overwrite_power_multiplier_5_plus' => 1.15,
            ],
        ],
        65 => [
            5 => ['accuracy_delta_points' => 5, 'sp_pressure_rate' => 0.03],
            9 => ['accuracy_delta_points' => 8, 'sp_pressure_rate' => 0.05],
        ],
        66 => [
            1 => ['guard_rate' => 0.20],
            5 => ['guard_rate' => 0.20, 'cleanse_harmful_states' => true],
            9 => ['guard_rate' => 0.25],
        ],
        67 => [
            1 => ['resource_gain_event' => 'hp_sp_conversion_success'],
        ],
        68 => [
            1 => ['resource_gain_event' => 'job_art_hit'],
            5 => ['break_rate' => 0.10, 'break_rounds' => 2],
            9 => ['break_rate' => 0.15, 'break_rounds' => 3],
        ],
        69 => [
            1 => ['resource_gain_points' => 0],
        ],
        85 => [
            1 => ['field_operation' => 'deploy', 'field_selection_mode' => 'fixed', 'field_key' => 'star_light'],
            5 => ['requires_trusted_field' => true, 'field_operation' => 'lock', 'field_lock_rounds' => 2],
            9 => ['field_operation' => 'overlay', 'field_selection_mode' => 'overlay_fixed', 'field_key' => 'melody', 'current_job_only' => true],
        ],
    ];

    /** @var list<int> */
    private const FULL_EFFECT_CURRENT_JOBS = [24, 32, 45, 52, 53, 60, 61, 62, 63, 64, 65, 66, 67, 68, 69, 85];

    private readonly JobArtLineageCatalog $lineageCatalog;

    public function __construct(?JobArtLineageCatalog $lineageCatalog = null)
    {
        $this->lineageCatalog = $lineageCatalog ?? new JobArtLineageCatalog();
    }

    public function supportsCurrentJob(?int $jobId): bool
    {
        return $jobId !== null && isset(self::CURRENT_JOB_TIERS[$jobId]);
    }

    public function currentJobTier(?int $jobId): ?string
    {
        return $jobId !== null ? (self::CURRENT_JOB_TIERS[$jobId] ?? null) : null;
    }

    /** @return array<int, string> */
    public function supportedCurrentJobs(): array
    {
        return self::CURRENT_JOB_TIERS;
    }

    public function effectCoverageForCurrentJob(?int $jobId): ?string
    {
        if (! $this->supportsCurrentJob($jobId)) {
            return null;
        }

        return in_array($jobId, self::FULL_EFFECT_CURRENT_JOBS, true)
            ? 'full_v2_effect'
            : 'resource_v2_master_effect_fallback';
    }

    public function isTrustedCurrentJobArt(?int $currentJobId, Skill $skill): bool
    {
        return $this->supportsCurrentJob($currentJobId)
            && (int) $skill->job_id === $currentJobId
            && $this->isTrustedArtProfile($skill);
    }

    public function isTrustedArtProfile(Skill $skill): bool
    {
        return $skill->isJobArt()
            && $this->lineages()->forJob((int) $skill->job_id) !== null
            && isset(self::RANK_PROFILES[(int) $skill->learn_rank]);
    }

    public function isSamePrimaryLineage(?int $currentJobId, Skill $skill): bool
    {
        $current = $this->lineages()->forJob($currentJobId);
        $source = $this->lineages()->forArt($skill);

        return $current !== null
            && $source !== null
            && $current['lineage_key'] === $source['lineage_key'];
    }

    /** @return array<string, int|string>|null */
    public function jobResourceMetadata(?int $jobId): ?array
    {
        if (! $this->supportsCurrentJob($jobId)) {
            return null;
        }

        $lineage = $this->lineages()->forJob($jobId);
        $profile = $lineage !== null ? (self::LINEAGE_PROFILES[$lineage['lineage_key']] ?? null) : null;

        return $profile !== null
            ? array_merge([
                'lineage_key' => $lineage['lineage_key'],
                'lineage_name' => $lineage['lineage_name'],
                'tier' => self::CURRENT_JOB_TIERS[$jobId],
            ], $profile)
            : null;
    }

    /** @return array<string, int|float|string|bool>|null */
    public function artResourceMetadata(Skill $skill): ?array
    {
        return $this->artResourceMetadataForJobRank((int) $skill->job_id, (int) $skill->learn_rank);
    }

    /** @return array<string, int|float|string|bool>|null */
    public function artResourceMetadataForJobRank(?int $jobId, int $rank): ?array
    {
        $lineage = $this->lineages()->forJob($jobId);
        $lineageProfile = $lineage !== null ? (self::LINEAGE_PROFILES[$lineage['lineage_key']] ?? null) : null;
        $rankProfile = self::RANK_PROFILES[$rank] ?? null;
        if ($jobId === null || $lineageProfile === null || $rankProfile === null) {
            return null;
        }

        $profile = array_merge(
            $lineageProfile,
            $rankProfile,
            $this->lineageRankDefaults($lineage['lineage_key'], $rank),
            self::ART_OVERRIDES[$jobId][$rank] ?? [],
        );

        return array_merge([
            'lineage_key' => $lineage['lineage_key'],
            'lineage_name' => $lineage['lineage_name'],
            'job_id' => $jobId,
            'learn_rank' => $rank,
            'effect_coverage' => $this->effectCoverageForCurrentJob($jobId) ?? 'inheritance_profile_only',
        ], $profile);
    }

    /** @return array<string, int|float|string|bool>|null */
    public function artFieldMetadata(Skill $skill): ?array
    {
        $metadata = $this->artResourceMetadata($skill);

        return $metadata !== null && array_key_exists('field_operation', $metadata) ? $metadata : null;
    }

    /** @return array<string, int|float|string|bool>|null */
    public function artPenetrationMetadata(Skill $skill): ?array
    {
        $metadata = $skill->isJobArt() ? $this->artResourceMetadata($skill) : null;

        return $metadata !== null
            && ($metadata['penetration_type'] ?? null) === 'physical_def'
            && isset($metadata['penetration_rate'])
                ? $metadata
                : null;
    }

    public function supportsLineageCapability(?int $jobId, string $capability): bool
    {
        if (! $this->supportsCurrentJob($jobId)) {
            return false;
        }

        $lineage = $this->lineages()->forJob($jobId)['lineage_key'] ?? null;

        return match ($capability) {
            'field' => $lineage === 'field',
            'penetration' => $lineage === 'pierce'
                && ($this->artResourceMetadataForJobRank($jobId, 5)['penetration_rate'] ?? null) !== null,
            'penetration_stance' => $jobId === 62,
            default => false,
        };
    }

    /** @return array<string, int|string> */
    private function lineageRankDefaults(string $lineageKey, int $rank): array
    {
        if ($rank !== 1) {
            return [];
        }

        return match ($lineageKey) {
            'eclipse', 'break' => ['resource_gain_event' => 'job_art_hit'],
            'transmute' => ['resource_gain_event' => 'hp_sp_conversion_success'],
            'command' => ['resource_gain_points' => 0],
            default => [],
        };
    }

    private function lineages(): JobArtLineageCatalog
    {
        return $this->lineageCatalog;
    }
}
