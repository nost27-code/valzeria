<?php

namespace App\Services;

use App\Models\Character;
use App\Models\City;
use App\Models\ExplorationMap;
use App\Models\TownMapRegistration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MapSurveyService
{
    public function start(Character $character, ExplorationMap $map, City $town, bool $bankConfirmed = false): TownMapRegistration
    {
        return $this->startMany($character, [$map->id], $town, $bankConfirmed)->firstOrFail();
    }

    /**
     * @param  array<int, int|string>  $mapIds
     * @return Collection<int, TownMapRegistration>
     */
    public function startMany(Character $character, array $mapIds, City $town, bool $bankConfirmed = false): Collection
    {
        $ids = collect($mapIds)
            ->map(fn (int|string $mapId): int => (int) $mapId);

        if ($ids->isEmpty() || $ids->contains(fn (int $mapId): bool => $mapId <= 0)) {
            throw new \RuntimeException('調査する地図を選んでください。');
        }
        $ids = $ids->unique()->sort()->values();

        return DB::transaction(function () use ($character, $ids, $town, $bankConfirmed): Collection {
            $character = Character::query()->whereKey($character->id)->lockForUpdate()->firstOrFail();
            $maps = ExplorationMap::query()
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($maps->count() !== $ids->count()
                || $maps->contains(fn (ExplorationMap $map): bool => $map->owner_character_id !== $character->id || $map->status !== 'uninvestigated')) {
                throw new \RuntimeException('この地図は調査に出せません。');
            }

            $existingRegistrations = TownMapRegistration::query()
                ->whereIn('map_id', $ids)
                ->orderBy('map_id')
                ->lockForUpdate()
                ->get();
            if ($existingRegistrations->isNotEmpty()) {
                throw new \RuntimeException('この地図は調査に出せません。');
            }

            $surveyCosts = $maps
                ->map(fn (ExplorationMap $map): array => [
                    'map_id' => (int) $map->id,
                    'map_grade' => (string) $map->map_grade,
                    'cost' => $this->cost($map),
                ])
                ->values();
            $totalCost = (int) $surveyCosts->sum('cost');
            $minutes = (int) config('exploration_maps.survey.base_minutes');
            $surveyStartedAt = now();
            $surveyCompletedAt = $surveyStartedAt->copy()->addMinutes($minutes);
            app(BankService::class)->spendForPayment(
                $character,
                $totalCost,
                $bankConfirmed,
                'map_survey',
                '探索の地図の遠征調査費',
                ExplorationMap::class,
                $maps->count() === 1 ? (int) $maps->first()->id : null,
                [
                    'town_id' => (int) $town->id,
                    'survey_map_ids' => $ids->all(),
                    'survey_map_count' => $maps->count(),
                    'survey_costs' => $surveyCosts->all(),
                ],
            );

            return $maps->map(function (ExplorationMap $map) use ($town, $surveyStartedAt, $surveyCompletedAt): TownMapRegistration {
                $cost = $this->cost($map);
                $registration = TownMapRegistration::create([
                    'map_id' => $map->id,
                    'town_id' => $town->id,
                    'survey_status' => 'completed',
                    'survey_cost' => $cost,
                    'survey_started_at' => $surveyStartedAt,
                    'survey_completed_at' => $surveyCompletedAt,
                    'exploration_limit' => $map->exploration_limit,
                    'remaining_explorations' => $map->exploration_limit,
                    'status' => 'surveyed',
                ]);
                $map->update(['status' => 'surveyed']);

                return $registration;
            });
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

    public function cost(ExplorationMap $map): int
    {
        $costs = $this->costs();

        return $costs[$map->map_grade] ?? $costs['normal'];
    }

    /** @return array<string, int> */
    public function costs(): array
    {
        return collect(config('exploration_maps.survey.costs', []))
            ->mapWithKeys(fn ($cost, $grade) => [(string) $grade => max(0, (int) $cost)])
            ->all();
    }
}
