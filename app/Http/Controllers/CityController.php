<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Area;
use App\Models\City;
use App\Services\CityPopulationService;
use App\Services\FerdiaMapService;

class CityController extends Controller
{
    public function index(CityPopulationService $cityPopulationService, FerdiaMapService $ferdiaMapService)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('home');
        }
        $character->refresh();
        $ferdiaMapService->relocateFromDisabledRegion($character);

        // 全都市を取得（フェルディアの街は専用MAPタブ側で表示する）
        $cities = City::orderBy('sort_order', 'asc')
            ->get()
            ->reject(fn (City $city): bool => $ferdiaMapService->isFerdiaCityId((int) $city->id))
            ->values();
        
        $highestCity = $character->highestCity;
        // 最高到達街がない（初期データ不良等）場合は一番最初の街を最高とする
        $highestCityOrder = $highestCity ? $highestCity->sort_order : 0;
        $highestCityName = $highestCity ? (string) $highestCity->name : '未到達';
        $cityPopulationCounts = $cityPopulationService->countsByCity();
        $cityIconSamples = $cityPopulationService->iconSamplesByCity(12);
        $ferdiaMap = $ferdiaMapService->mapFor($character);
        $currentLocationName = $this->currentLocationName($character, $ferdiaMapService, $ferdiaMap);
        $initialMapRegion = $this->initialMapRegion($character, $ferdiaMapService, $ferdiaMap);

        return view('city.index', compact('character', 'cities', 'highestCityOrder', 'highestCityName', 'cityPopulationCounts', 'cityIconSamples', 'ferdiaMap', 'currentLocationName', 'initialMapRegion'));
    }

    public function travel(Request $request, City $city, FerdiaMapService $ferdiaMapService)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('home');
        }

        $highestCity = $character->highestCity;
        $highestCityOrder = $highestCity ? $highestCity->sort_order : 0;

        $canTravel = $ferdiaMapService->isFerdiaCityId((int) $city->id)
            ? $ferdiaMapService->canTravelCity($character, $city)
            : $city->sort_order <= $highestCityOrder;

        // 解放条件チェック: 通常都市は最高到達街以下、フェルディア都市はMAP発見済みであること
        if ($canTravel) {
            $hatchedValmons = app(\App\Services\ValmonService::class)->hatchActiveEggs($character);
            $character->current_city_id = $city->id;
            $character->save();
            app(\App\Services\PlayerLifecycleEventService::class)->recordCityReached($character, $city);
            app(\App\Services\ExplorationStateService::class)->reset($character);
            if ($request->boolean('from_battle_result')) {
                session()->forget('lastBattleData');
            }
            session(['current_location' => 'town']);

            $routeParams = $request->boolean('from_battle_result') ? ['skip_resume' => 1] : [];
            $redirect = redirect()->route('home', $routeParams)->with('success', "{$city->name} に移動しました。");
            if (!empty($hatchedValmons)) {
                $message = '卵が淡く光りはじめた……<br>';
                foreach ($hatchedValmons as $hatched) {
                    $message .= $hatched['name'] . 'が生まれた！<br>';
                    $message .= ($hatched['already_had'] ?? false)
                        ? 'すでに仲間にしたことのあるヴァルモンです。<br>'
                        : '新しいヴァルモンが仲間になった！<br>';
                }
                $redirect->with('message', $message);
            }

            return $redirect;
        }

        return back()->with('error', 'まだその街へは移動できません。');
    }

    public function openFerdiaArea(Request $request, Area $area, FerdiaMapService $ferdiaMapService)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('home');
        }

        $areaId = (int) $area->id;
        if (!$ferdiaMapService->isFerdiaAreaId($areaId) || !$ferdiaMapService->canAccessArea($character, $areaId)) {
            return back()->with('error', 'まだその探索地へは進めません。');
        }

        $character->current_city_id = (int) $area->city_id;
        $character->save();
        if ($area->city) {
            app(\App\Services\PlayerLifecycleEventService::class)->recordCityReached($character, $area->city);
        }
        $character->refresh();

        session([
            'current_location' => 'dungeon',
            'target_area_id' => $areaId,
            'target_area_purpose' => 'map',
        ]);

        return redirect()
            ->route('home', ['skip_resume' => 1])
            ->with('success', "{$area->name} を探索先に選びました。");
    }

    private function currentLocationName($character, FerdiaMapService $ferdiaMapService, ?array $ferdiaMap): string
    {
        $currentCity = $character->currentCity;
        if (!$currentCity) {
            return '不明';
        }

        if (
            $ferdiaMapService->isFerdiaCityId((int) $currentCity->id)
            && !$ferdiaMapService->canTravelCity($character, $currentCity)
        ) {
            return (string) ($ferdiaMap['current_node']['name'] ?? 'フェルディア簡易拠点');
        }

        return (string) $currentCity->name;
    }

    private function initialMapRegion($character, FerdiaMapService $ferdiaMapService, ?array $ferdiaMap): string
    {
        if (empty($ferdiaMap)) {
            return 'valzeria';
        }

        return $ferdiaMapService->isFerdiaCityId((int) ($character->current_city_id ?? 0))
            ? 'ferdia'
            : 'valzeria';
    }
}
