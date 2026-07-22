<?php

namespace App\Services;

use App\Models\Character;
use App\Models\ExplorationMap;
use App\Models\TownMapRegistration;
use Illuminate\Support\Facades\DB;

class ExplorationMapDiscardService
{
    public function discard(Character $character, ExplorationMap $map): void
    {
        DB::transaction(function () use ($character, $map): void {
            $map = ExplorationMap::lockForUpdate()->findOrFail($map->id);

            if ($map->owner_character_id !== $character->id) {
                throw new \RuntimeException('この地図は破棄できません。');
            }
            if (!in_array($map->status, ['uninvestigated', 'surveyed'], true)) {
                throw new \RuntimeException('公開中または処理中の地図は破棄できません。');
            }

            $registration = TownMapRegistration::where('map_id', $map->id)->lockForUpdate()->first();
            $registration?->update(['survey_status' => 'discarded', 'status' => 'discarded']);
            $map->update(['status' => 'discarded']);
        });
    }
}
