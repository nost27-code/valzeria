<?php

namespace App\Services;

use App\Models\MapExplorationBatch;
use App\Models\MapIncomeLog;

class MapIncomeService
{
    public function settle(MapExplorationBatch $batch): void
    {
        if (MapIncomeLog::where('batch_id', $batch->id)->exists() || $batch->executed_count <= 0) return;
        $batch->loadMissing('map.owner', 'registration');
        $paid = $batch->character_id === $batch->map->owner_character_id ? 0 : (int) $batch->total_fee;
        $owner = (int) floor($paid * ((int) config('exploration_maps.entry_fee.owner_share') / 100));
        $town = (int) floor($paid * ((int) config('exploration_maps.entry_fee.town_share') / 100));
        $system = max(0, $paid - $owner - $town);
        if ($owner > 0) app(GoldService::class)->add($batch->map->owner, $owner, 'map_entry_income', '探索の地図の入場料収益', MapExplorationBatch::class, $batch->id, ['map_id' => $batch->map_id]);
        if ($town > 0) $batch->registration->town()->increment('map_institute_development', $town);
        MapIncomeLog::create(['batch_id' => $batch->id, 'map_id' => $batch->map_id, 'registration_id' => $batch->registration_id, 'payer_character_id' => $batch->character_id, 'owner_character_id' => $batch->map->owner_character_id, 'executed_count' => $batch->executed_count, 'total_entry_fee' => $paid, 'owner_share' => $owner, 'town_share' => $town, 'system_share' => $system]);
    }
}
