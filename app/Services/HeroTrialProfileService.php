<?php

namespace App\Services;

use App\Models\Enemy;
use App\Models\EnemyAction;
use DomainException;
use Illuminate\Support\Collection;

class HeroTrialProfileService
{
    /**
     * @return Collection<string, array<string, mixed>>
     */
    public function profiles(): Collection
    {
        return collect(config('hero_trials.profiles', []));
    }

    /**
     * @return array<string, mixed>
     */
    public function profile(string $profileKey): array
    {
        $profile = $this->profiles()->get($profileKey);

        if (! is_array($profile) || empty($profile['phases'])) {
            throw new DomainException('指定された英雄試練プリセットは存在しません。');
        }

        $profile['phases'] = collect($profile['phases'])
            ->values()
            ->map(fn (array $phase): array => $this->resolveTrialMaster($phase))
            ->all();

        return $profile;
    }

    /**
     * @return Collection<int, Enemy>
     */
    public function enemies(string $profileKey): Collection
    {
        $profile = $this->profile($profileKey);

        return collect($profile['phases'])
            ->values()
            ->map(fn (array $phase, int $index): Enemy => $this->makeEnemy($phase, $index));
    }

    /**
     * @return list<string>
     */
    public function speciesLabels(string $profileKey): array
    {
        $labels = (array) config('enemy_species.labels', []);

        return collect($this->profile($profileKey)['phases'])
            ->flatMap(fn (array $phase): array => (array) ($phase['species_keys'] ?? []))
            ->unique()
            ->map(fn (string $speciesKey): string => (string) ($labels[$speciesKey] ?? '種族不明'))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $phase
     */
    private function makeEnemy(array $phase, int $index): Enemy
    {
        foreach (['name', 'species_keys', 'max_hp', 'str', 'def', 'agi', 'mag', 'spr', 'luk'] as $required) {
            if (! array_key_exists($required, $phase)) {
                throw new DomainException("英雄試練プリセットの形態設定に {$required} がありません。");
            }
        }

        $speciesKeys = array_values((array) $phase['species_keys']);

        $enemy = new Enemy([
            'name' => (string) $phase['name'],
            'level' => 255,
            'max_hp' => max(1, (int) $phase['max_hp']),
            'max_mp' => 0,
            'str' => max(1, (int) $phase['str']),
            'def' => max(1, (int) $phase['def']),
            'agi' => max(1, (int) $phase['agi']),
            'mag' => max(1, (int) $phase['mag']),
            'spr' => max(1, (int) $phase['spr']),
            'luk' => max(1, (int) $phase['luk']),
            'is_boss' => true,
            'role_key' => 'boss',
            'family_key' => 'standard',
            'variant_key' => 'holy',
            'species_key' => $speciesKeys[0],
            'species_keys' => $speciesKeys,
            'type_name' => (string) ($phase['type_name'] ?? '標準型'),
            'normal_attack_type' => (string) ($phase['normal_attack_type'] ?? 'physical'),
            'exp_reward' => 0,
            'job_exp_reward' => 0,
            'gold_reward' => 0,
            'appearance_weight' => 0,
            'skip_danger_bonus' => true,
            'skip_durability_bonus' => true,
            'hero_trial_phase_key' => (string) ($phase['key'] ?? 'phase_' . ($index + 1)),
        ]);
        $enemy->setRelation('actions', $this->makeActions($phase, $index));
        $enemy->setRelation('area', null);

        return $enemy;
    }

    /**
     * @param  array<string, mixed>  $phase
     * @return Collection<int, EnemyAction>
     */
    private function makeActions(array $phase, int $phaseIndex): Collection
    {
        return collect((array) ($phase['actions'] ?? []))
            ->values()
            ->map(function (array $action, int $actionIndex) use ($phaseIndex): EnemyAction {
                return new EnemyAction([
                    'id' => 900_000 + ($phaseIndex * 100) + $actionIndex + 1,
                    'enemy_id' => 0,
                    'action_key' => (string) ($action['action_key'] ?? 'trial_action_'.($actionIndex + 1)),
                    'name' => (string) ($action['name'] ?? '試練主の技'),
                    'action_type' => (string) ($action['action_type'] ?? 'physical'),
                    'selection_weight' => max(1, (int) ($action['selection_weight'] ?? 100)),
                    'power_percent' => max(0, (int) ($action['power_percent'] ?? 100)),
                    'hit_count' => max(1, (int) ($action['hit_count'] ?? 1)),
                    'effect_percent' => max(0, (int) ($action['effect_percent'] ?? 0)),
                    'duration_turns' => max(0, (int) ($action['duration_turns'] ?? 0)),
                    'cooldown_turns' => max(0, (int) ($action['cooldown_turns'] ?? 0)),
                    'max_uses_per_battle' => $this->nullableInt($action['max_uses_per_battle'] ?? null),
                    'trigger_turn' => $this->nullableInt($action['trigger_turn'] ?? null),
                    'trigger_key' => $action['trigger_key'] ?? null,
                    'trigger_value' => $this->nullableInt($action['trigger_value'] ?? null),
                    'can_use_on_first_turn' => (bool) ($action['can_use_on_first_turn'] ?? true),
                    'is_telegraphed' => (bool) ($action['is_telegraphed'] ?? false),
                    'telegraph_turns' => max(0, (int) ($action['telegraph_turns'] ?? 0)),
                    'can_be_guarded' => (bool) ($action['can_be_guarded'] ?? false),
                    'guard_reduction_rate' => (float) ($action['guard_reduction_rate'] ?? 0),
                    'cancel_on_enemy_death' => (bool) ($action['cancel_on_enemy_death'] ?? true),
                    'guarantee_first_use' => (bool) ($action['guarantee_first_use'] ?? false),
                    'sort_order' => (int) ($action['sort_order'] ?? $actionIndex),
                ]);
            });
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    /**
     * @param  array<string, mixed>  $phase
     * @return array<string, mixed>
     */
    private function resolveTrialMaster(array $phase): array
    {
        $masterKey = trim((string) ($phase['trial_master_key'] ?? ''));
        $master = config("hero_trials.trial_masters.{$masterKey}");

        if ($masterKey === '' || ! is_array($master)) {
            throw new DomainException('英雄試練プリセットに有効な試練主が設定されていません。');
        }

        $name = trim((string) ($master['name'] ?? ''));
        $speciesLabels = (array) config('enemy_species.labels', []);
        $speciesKeys = array_values(array_unique(array_filter(array_map(
            static fn ($speciesKey): string => trim((string) $speciesKey),
            (array) ($master['species_keys'] ?? [])
        ))));
        if ($name === '' || count($speciesKeys) !== 2) {
            throw new DomainException("英雄試練の試練主 {$masterKey} に有効な名前または種族が設定されていません。");
        }
        foreach ($speciesKeys as $speciesKey) {
            if (! array_key_exists($speciesKey, $speciesLabels)) {
                throw new DomainException("英雄試練の試練主 {$masterKey} の種族 {$speciesKey} は定義されていません。");
            }
        }

        return array_merge($phase, [
            'name' => $name,
            'species_key' => $speciesKeys[0],
            'species_keys' => $speciesKeys,
        ]);
    }
}
