<?php

namespace App\Services;

class ExplorationMapRewardProfileService
{
    /** @return array<string, mixed> */
    public function definition(string $profile, ?string $grade = null): array
    {
        $base = (array) config("exploration_maps.reward_profiles.{$profile}", []);
        $override = $grade
            ? (array) config("exploration_maps.grade_reward_profile_overrides.{$grade}.{$profile}", [])
            : [];

        if ($override === []) {
            return $base;
        }

        $definition = array_replace($base, $override);
        $definition['modifiers'] = array_replace(
            (array) ($base['modifiers'] ?? []),
            (array) ($override['modifiers'] ?? []),
        );

        return $definition;
    }

    /** @return array<string, int|float> */
    public function modifiers(string $profile, ?string $grade = null): array
    {
        return (array) ($this->definition($profile, $grade)['modifiers'] ?? []);
    }

    public function explorationLimitMultiplier(string $profile, ?string $grade = null): float
    {
        return (float) ($this->definition($profile, $grade)['exploration_limit_multiplier'] ?? 1);
    }

    public function label(string $profile): ?string
    {
        $label = $this->definition($profile)['label'] ?? null;

        return $label !== null ? (string) $label : null;
    }

    /** @param array<string, int|float> $modifiers
     *  @return array{amount: int, cap: int}
     */
    public function jobExpReward(int $baseJobExp, array $modifiers): array
    {
        $cap = max(LevelService::MAX_JOB_EXP_GAIN, (int) ($modifiers['job_exp_cap'] ?? LevelService::MAX_JOB_EXP_GAIN));
        $amount = app(LevelService::class)->capJobExpGain(
            (int) floor(max(0, $baseJobExp) * (float) ($modifiers['job_exp_multiplier'] ?? 1))
                + max(0, (int) ($modifiers['job_exp_flat_bonus'] ?? 0)),
            $cap,
        );

        return ['amount' => $amount, 'cap' => $cap];
    }
}
