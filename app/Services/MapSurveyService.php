<?php

namespace App\Services;

use App\Models\Character;
use App\Models\City;
use App\Models\ExplorationMap;
use App\Models\TownMapRegistration;
use Illuminate\Support\Facades\DB;

class MapSurveyService
{
    public function start(Character $character, ExplorationMap $map, City $town): TownMapRegistration
    {
        return DB::transaction(function () use ($character, $map, $town) {
            $map = ExplorationMap::lockForUpdate()->findOrFail($map->id);
            if ($map->owner_character_id !== $character->id || $map->status !== 'uninvestigated') throw new \RuntimeException('この地図は調査に出せません。');
            $cost = $this->cost();
            $minutes = (int) config('exploration_maps.survey.base_minutes');
            app(GoldService::class)->spend($character, $cost, 'map_survey', '探索の地図の遠征調査費', ExplorationMap::class, $map->id, ['town_id' => $town->id]);
            $surveyedAt = now()->addMinutes($minutes);
            $registration = TownMapRegistration::create(['map_id' => $map->id, 'town_id' => $town->id, 'survey_status' => 'completed', 'survey_cost' => $cost, 'survey_started_at' => now(), 'survey_completed_at' => $surveyedAt, 'exploration_limit' => $map->exploration_limit, 'remaining_explorations' => $map->exploration_limit, 'status' => 'surveyed']);
            $map->update(['status' => 'surveyed']);
            return $registration;
        });
    }

    public function complete(Character $character, TownMapRegistration $registration): TownMapRegistration
    {
        return DB::transaction(function () use ($character, $registration) {
            $registration = TownMapRegistration::with('map')->lockForUpdate()->findOrFail($registration->id);
            if ($registration->map->owner_character_id !== $character->id || $registration->survey_status !== 'surveying') throw new \RuntimeException('この調査は完了できません。');
            if ($registration->survey_completed_at->isFuture()) throw new \RuntimeException('遠征調査はまだ完了していません。');
            $registration->update(['survey_status' => 'completed', 'status' => 'surveyed']);
            $registration->map->update(['status' => 'surveyed']);
            return $registration->fresh(['map', 'town']);
        });
    }

    public function cost(): int
    {
        return (int) config('exploration_maps.survey.base_cost');
    }
}
