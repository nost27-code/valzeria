<?php

namespace App\Services\Nation\Raid\Simulation;

use App\Services\Nation\Raid\NationRaidRules;

/** Phase 2の匿名snapshotから、レイド種族特攻の実効分布を集計する。 */
final class NationRaidKillerPopulationSummary
{
    private const RATE_PRECISION = 6;

    private const EPSILON = 0.000001;

    /**
     * @param  list<array<string, mixed>>  $characters
     * @return array{
     *   observed_characters:int,
     *   matched_characters:int,
     *   unmatched_characters:int,
     *   unavailable_characters:int,
     *   match_rate:float,
     *   average_damage_rate:float,
     *   max_damage_rate:float,
     *   max_raw_combined_damage_rate:float,
     *   cap_binding_characters:int,
     *   damage_rate_distribution:list<array{damage_rate:float,characters:int}>
     * }
     */
    public function summarize(array $characters, ?int $unavailableCharacters = null): array
    {
        $rates = [];
        $rawRates = [];
        $distribution = [];
        $unavailableFromRows = 0;

        foreach ($characters as $character) {
            $killer = is_array($character['raid_killer'] ?? null) ? $character['raid_killer'] : null;
            $rate = $killer['damage_rate'] ?? null;
            if ($killer === null || (! is_int($rate) && ! is_float($rate))) {
                $unavailableFromRows++;

                continue;
            }

            $effectiveRate = round((float) $rate, self::RATE_PRECISION);
            $rawRate = $this->rawCombinedRate($killer, $effectiveRate);
            $rates[] = $effectiveRate;
            $rawRates[] = $rawRate;

            $key = number_format($effectiveRate, self::RATE_PRECISION, '.', '');
            $distribution[$key] = ($distribution[$key] ?? 0) + 1;
        }

        ksort($distribution, SORT_STRING);
        $distributionRows = [];
        foreach ($distribution as $rate => $charactersAtRate) {
            $distributionRows[] = [
                'damage_rate' => (float) $rate,
                'characters' => $charactersAtRate,
            ];
        }

        $observed = count($rates);
        $matched = count(array_filter($rates, static fn (float $rate): bool => $rate > 0.0));
        $unavailable = max(0, $unavailableCharacters ?? $unavailableFromRows);
        $capBinding = count(array_filter(
            $rawRates,
            static fn (float $rate): bool => $rate >= NationRaidRules::BOSS_KILLER_DAMAGE_RATE_CAP - self::EPSILON,
        ));

        return [
            'observed_characters' => $observed,
            'matched_characters' => $matched,
            'unmatched_characters' => $observed - $matched,
            'unavailable_characters' => $unavailable,
            'match_rate' => $observed > 0 ? (float) $matched / $observed : 0.0,
            'average_damage_rate' => $observed > 0 ? array_sum($rates) / $observed : 0.0,
            'max_damage_rate' => $rates !== [] ? max($rates) : 0.0,
            'max_raw_combined_damage_rate' => $rawRates !== [] ? max($rawRates) : 0.0,
            'cap_binding_characters' => $capBinding,
            'damage_rate_distribution' => $distributionRows,
        ];
    }

    /** @param array<string, mixed> $killer */
    private function rawCombinedRate(array $killer, float $fallback): float
    {
        $effects = $killer['effects'] ?? null;
        if (! is_array($effects)) {
            return $fallback;
        }

        $rate = 0.0;
        foreach ($effects as $effect) {
            $effectRate = is_array($effect) ? ($effect['damage_rate'] ?? null) : null;
            if (is_int($effectRate) || is_float($effectRate)) {
                $rate += (float) $effectRate;
            }
        }

        return round($rate * NationRaidRules::BOSS_KILLER_DAMAGE_RATE_MULTIPLIER, self::RATE_PRECISION);
    }
}
