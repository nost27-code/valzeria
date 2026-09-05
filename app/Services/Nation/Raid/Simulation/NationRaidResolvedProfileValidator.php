<?php

namespace App\Services\Nation\Raid\Simulation;

use App\Services\Nation\Raid\NationRaidRules;
use Throwable;

/** hash検証前後で、compact resolved profileのcontextと結果契約を厳密に検査する。 */
final readonly class NationRaidResolvedProfileValidator
{
    public function __construct(private NationRaidResolvedProfileCacheHasher $hasher) {}

    /** @param array<string, mixed> $profile @return list<string> */
    public function validate(array $profile, string $characterKey, string $rulesetHash): array
    {
        $errors = [];
        $contextPayload = is_array($profile['context'] ?? null) ? $profile['context'] : [];
        try {
            $context = NationRaidResolvedProfileContext::fromArray($contextPayload, $characterKey);
        } catch (Throwable) {
            return ['invalid_resolved_context'];
        }

        if (($profile['context_key'] ?? null) !== $context->key()) {
            $errors[] = 'resolved_context_key_mismatch';
        }
        if (! is_string($profile['profile_hash'] ?? null)
            || ! preg_match('/^[a-f0-9]{64}$/', $profile['profile_hash'])
            || $profile['profile_hash'] !== $this->hasher->profileHash($profile)
        ) {
            $errors[] = 'resolved_profile_hash_mismatch';
        }

        $result = is_array($profile['result'] ?? null) ? $profile['result'] : [];
        $expected = [
            'battle_type' => NationRaidRules::BATTLE_TYPE,
            'stage' => $context->stage,
            'form' => $context->startingForm,
            'boss_species_key' => NationRaidRules::BOSS_SPECIES_KEY,
            'sortie_seed' => $context->sortieSeed,
            'ruleset_hash' => $rulesetHash,
            'strategy' => $context->strategy,
        ];
        foreach ($expected as $key => $value) {
            if (($result[$key] ?? null) !== $value) {
                $errors[] = "resolved_result_context_mismatch:{$key}";
            }
        }

        foreach ([
            'turns_completed',
            'player_remaining_hp',
            'boss_virtual_remaining_hp',
            'calculated_boss_damage',
            'max_one_action_damage',
            't20_starting_sp',
            'reservation_failure_count',
        ] as $key) {
            if (! is_int($result[$key] ?? null)) {
                $errors[] = "invalid_resolved_result_integer:{$key}";
            }
        }
        if (is_int($result['turns_completed'] ?? null)
            && ($result['turns_completed'] < 1 || $result['turns_completed'] > NationRaidRules::MAX_TURNS)
        ) {
            $errors[] = 'out_of_range_resolved_turns_completed';
        }
        foreach (['player_remaining_hp', 'boss_virtual_remaining_hp', 'calculated_boss_damage', 'max_one_action_damage', 'reservation_failure_count'] as $key) {
            if (is_int($result[$key] ?? null) && $result[$key] < 0) {
                $errors[] = "negative_resolved_result:{$key}";
            }
        }
        if (is_int($result['calculated_boss_damage'] ?? null)
            && is_int($result['max_one_action_damage'] ?? null)
            && $result['max_one_action_damage'] > $result['calculated_boss_damage']
        ) {
            $errors[] = 'resolved_max_one_action_exceeds_total';
        }
        if (is_int($result['boss_virtual_remaining_hp'] ?? null)
            && $result['boss_virtual_remaining_hp'] > NationRaidRules::VIRTUAL_MAX_HP
        ) {
            $errors[] = 'out_of_range_resolved_boss_virtual_remaining_hp';
        }
        if (is_int($result['turns_completed'] ?? null) && is_int($result['t20_starting_sp'] ?? null)) {
            $validT20Sp = $result['turns_completed'] < NationRaidRules::MAX_TURNS
                ? $result['t20_starting_sp'] === -1
                : $result['t20_starting_sp'] >= 0 && $result['t20_starting_sp'] <= NationRaidRules::BOSS_MAX_SP;
            if (! $validT20Sp) {
                $errors[] = 'invalid_resolved_t20_starting_sp';
            }
        }
        if (! in_array($result['outcome'] ?? null, ['survived', 'defeated'], true)) {
            $errors[] = 'invalid_resolved_outcome';
        } elseif (is_int($result['player_remaining_hp'] ?? null)
            && (($result['outcome'] === 'defeated') !== ($result['player_remaining_hp'] === 0))
        ) {
            $errors[] = 'resolved_outcome_hp_mismatch';
        }
        $denialReasons = $result['ultimate_denial_reasons'] ?? null;
        $allowedDenialReasons = [
            'aim_sp_pressure',
            'transmute_resource_slow',
            'turn_18_delay',
            'turn_20_delay',
            'insufficient_sp',
        ];
        if (! is_array($denialReasons)
            || ! array_is_list($denialReasons)
            || count($denialReasons) !== count(array_unique($denialReasons))
            || array_filter(
                $denialReasons,
                static fn (mixed $reason): bool => ! is_string($reason)
                    || ! in_array($reason, $allowedDenialReasons, true),
            ) !== []
        ) {
            $errors[] = 'invalid_resolved_ultimate_denial_reasons';
        }

        $metrics = is_array($result['metrics'] ?? null) ? $result['metrics'] : [];
        foreach (self::metricKeys() as $key) {
            if (! is_int($metrics[$key] ?? null) || $metrics[$key] < 0) {
                $errors[] = "invalid_resolved_metric:{$key}";
            }
        }
        if (is_int($metrics['cap_binding_hits'] ?? null)
            && is_int($metrics['enemy_damage_actions'] ?? null)
            && $metrics['cap_binding_hits'] > $metrics['enemy_damage_actions']
        ) {
            $errors[] = 'resolved_cap_hits_exceed_enemy_actions';
        }
        if (is_int($result['turns_completed'] ?? null)) {
            foreach (['observations', 'ultimate_executed', 'enemy_damage_actions', 'counterplay_applied'] as $key) {
                if (is_int($metrics[$key] ?? null) && $metrics[$key] > $result['turns_completed']) {
                    $errors[] = "resolved_metric_exceeds_turns:{$key}";
                }
            }
        }
        if (is_int($metrics['enemy_damage_actions'] ?? null)) {
            foreach (['guard_consumed_actions', 'parry_succeeded_actions', 'guts_triggered_actions'] as $key) {
                if (is_int($metrics[$key] ?? null) && $metrics[$key] > $metrics['enemy_damage_actions']) {
                    $errors[] = "resolved_defense_metric_exceeds_enemy_actions:{$key}";
                }
            }
        }
        if (is_int($metrics['counter_damage'] ?? null)
            && is_int($result['calculated_boss_damage'] ?? null)
            && $metrics['counter_damage'] > $result['calculated_boss_damage']
        ) {
            $errors[] = 'resolved_counter_damage_exceeds_total';
        }

        return array_values(array_unique($errors));
    }

    /** @return list<string> */
    public static function metricKeys(): array
    {
        return [
            'observations',
            'ultimate_executed',
            'cap_binding_hits',
            'enemy_damage_actions',
            'counterplay_applied',
            'guard_consumed_actions',
            'parry_succeeded_actions',
            'guts_triggered_actions',
            'actual_hp_loss',
            'counter_damage',
        ];
    }
}
