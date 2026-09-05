<?php

namespace App\Services\Nation\Raid\Simulation;

use App\Services\Nation\Raid\NationRaidJson;
use App\Services\Nation\Raid\NationRaidRules;
use InvalidArgumentException;

/** 実測した活動時刻だけを使い、7日間の国家連携窓を決定的に再生する。 */
final class NationRaidCoordinationTimingModel
{
    public const VERSION = 'nation-raid-coordination-empirical-minute-bootstrap-v1';

    /** @return array<string, mixed> */
    public function contract(): array
    {
        return [
            'version' => self::VERSION,
            'modeled' => true,
            'authoritative_for_balance_gate' => true,
            'timezone' => $this->timezone(),
            'sample_source' => 'actual_battle_logs_created_at_as_local_minute_of_day',
            'projection' => 'same_character_empirical_minute_bootstrap_with_replacement',
            'event_minute' => 'raid_day_offset_plus_sampled_local_minute',
            'window_minutes' => NationRaidRules::COORDINATION_WINDOW_MINUTES,
            'window_boundary' => 'participants_at_or_before_current_minus_window_are_expired',
            'registration_order' => 'register_successful_sortie_before_rate_resolution',
            'same_character_repeat' => 'does_not_refresh_first_active_participation',
            'unaffiliated' => 'ineligible',
            'damage_rates' => NationRaidRules::COORDINATION_DAMAGE_RATES,
            'known_gaps' => [],
        ];
    }

    public function contractHash(): string
    {
        return hash('sha256', NationRaidJson::encode($this->contract(), JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $activity */
    public function eventMinute(array $activity, int $day, int $slot, int $seed, string $characterKey): int
    {
        if ($day < 1 || $day > 7 || $slot < 1 || $slot > 5 || $seed < 1 || $characterKey === '') {
            throw new InvalidArgumentException('Invalid raid coordination timing input.');
        }

        $samples = $activity['minute_of_day_samples'] ?? null;
        if (! is_array($samples) || $samples === []) {
            throw new InvalidArgumentException('Raid coordination timing samples are missing.');
        }
        foreach ($samples as $sample) {
            if (! is_int($sample) || $sample < 0 || $sample >= 1_440) {
                throw new InvalidArgumentException('Raid coordination timing sample is invalid.');
            }
        }

        $scope = "{$seed}|{$characterKey}|{$day}|{$slot}";
        $index = (int) (hexdec(substr(hash('sha256', $scope), 0, 8)) % count($samples));

        return (($day - 1) * 1_440) + $samples[$index];
    }

    /**
     * @param  array<string, array<string, int>>  $activeByNation
     * @return array{eligible:bool,unique_count:int,bonus_rate:float,newly_registered:bool}
     */
    public function register(
        array &$activeByNation,
        ?string $nationKey,
        string $characterKey,
        int $eventMinute,
    ): array {
        if ($nationKey === null) {
            return [
                'eligible' => false,
                'unique_count' => 0,
                'bonus_rate' => 0.0,
                'newly_registered' => false,
            ];
        }
        if ($nationKey === '' || $characterKey === '' || $eventMinute < 0) {
            throw new InvalidArgumentException('Invalid raid coordination participant input.');
        }

        $participants = $activeByNation[$nationKey] ?? [];
        $threshold = $eventMinute - NationRaidRules::COORDINATION_WINDOW_MINUTES;
        foreach ($participants as $key => $participatedAt) {
            if ($participatedAt <= $threshold) {
                unset($participants[$key]);
            }
        }

        $newlyRegistered = ! array_key_exists($characterKey, $participants);
        if ($newlyRegistered) {
            $participants[$characterKey] = $eventMinute;
        }
        ksort($participants, SORT_STRING);
        $activeByNation[$nationKey] = $participants;
        $uniqueCount = count($participants);

        return [
            'eligible' => true,
            'unique_count' => $uniqueCount,
            'bonus_rate' => NationRaidRules::coordinationDamageRate($uniqueCount),
            'newly_registered' => $newlyRegistered,
        ];
    }

    public function coordinationDamage(int $personalDamage, float $bonusRate): int
    {
        if ($personalDamage < 0 || $bonusRate < 0.0 || $bonusRate > 1.0) {
            throw new InvalidArgumentException('Invalid raid coordination damage input.');
        }

        return (int) floor($personalDamage * $bonusRate);
    }

    private function timezone(): string
    {
        if (function_exists('app')) {
            $container = app();
            if ($container->bound('config')) {
                return (string) $container->make('config')->get('app.timezone', 'UTC');
            }
        }

        return date_default_timezone_get();
    }
}
