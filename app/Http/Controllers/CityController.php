<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\City;

class CityController extends Controller
{
    public function index()
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('home');
        }
        $character->refresh();

        // 全都市を取得
        $cities = City::orderBy('sort_order', 'asc')->get();
        
        $highestCity = $character->highestCity;
        // 最高到達街がない（初期データ不良等）場合は一番最初の街を最高とする
        $highestCityOrder = $highestCity ? $highestCity->sort_order : 0;

        return view('city.index', compact('character', 'cities', 'highestCityOrder'));
    }

    public function travel(Request $request, City $city)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('home');
        }

        $highestCity = $character->highestCity;
        $highestCityOrder = $highestCity ? $highestCity->sort_order : 0;

        // 解放条件チェック: 対象の街のsort_orderが最高到達街のsort_order以下であること
        if ($city->sort_order <= $highestCityOrder) {
            $hatchedValmons = app(\App\Services\ValmonService::class)->hatchActiveEggs($character);
            $character->current_city_id = $city->id;
            $character->save();
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
}
