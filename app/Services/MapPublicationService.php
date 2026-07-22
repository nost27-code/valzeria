<?php

namespace App\Services;

use App\Models\Character;
use App\Models\TownMapRegistration;
use Illuminate\Support\Facades\DB;

class MapPublicationService
{
    public function __construct(private readonly ExplorationMapDifficultyService $difficulty) {}

    public function maxFee(TownMapRegistration $registration): int
    {
        $value = max(1, (int) $registration->map->map_level) * (int) config('exploration_maps.entry_fee.expected_value_per_level');
        $tier = $this->difficulty->threatTierForMap($registration->map);
        $maximum = $value * (float) config('exploration_maps.entry_fee.maximum_rate') * (float) $tier['max_fee_multiplier'];

        return max((int) config('exploration_maps.entry_fee.minimum'), (int) floor($maximum + 0.000001));
    }
    public function recommendedFee(TownMapRegistration $registration): int
    {
        $value = max(1, (int) $registration->map->map_level) * (int) config('exploration_maps.entry_fee.expected_value_per_level');
        return (int) floor($value * (float) config('exploration_maps.entry_fee.recommended_rate'));
    }
    public function feeOptions(TownMapRegistration $registration): array
    {
        $max = $this->maxFee($registration);
        $recommended = $this->recommendedFee($registration);
        $options = [
            ['label' => '無料', 'fee' => 0],
            ['label' => 'お手頃', 'fee' => (int) floor($max * 0.2)],
            ['label' => 'おすすめ', 'fee' => $recommended],
            ['label' => 'やや高め', 'fee' => (int) floor($max * 0.6)],
            ['label' => '高め', 'fee' => (int) floor($max * 0.8)],
            ['label' => '上限', 'fee' => $max],
        ];

        return collect($options)->unique('fee')->values()->all();
    }
    public function publish(Character $character, TownMapRegistration $registration, int $fee): TownMapRegistration
    {
        return DB::transaction(function () use ($character, $registration, $fee) {
            $registration = TownMapRegistration::with(['map.owner', 'town'])->lockForUpdate()->findOrFail($registration->id);
            if ($registration->map->owner_character_id !== $character->id || $registration->status !== 'surveyed') throw new \RuntimeException('この地図は公開できません。');
            if ($fee < 0 || $fee > $this->maxFee($registration)) throw new \RuntimeException('入場料が設定可能な上限を超えています。');
            $explorationLimit = max(1, (int) $registration->exploration_limit, (int) $registration->map->exploration_limit);
            $registration->update(['entry_fee_per_exploration' => $fee, 'entry_fee_changed_at' => now(), 'published_at' => now(), 'expires_at' => now()->addHours((int) config('exploration_maps.public_hours')), 'remaining_explorations' => $explorationLimit, 'consumed_explorations' => 0, 'status' => 'published']);
            $registration->map->update(['status' => 'published']);
            app(PublicLogService::class)->addMapPublishedLog($registration->map, $registration);
            return $registration->fresh(['map.owner', 'town']);
        });
    }
}
