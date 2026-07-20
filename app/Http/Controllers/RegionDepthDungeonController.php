<?php

namespace App\Http\Controllers;

use App\Models\Character;
use App\Services\ExplorationStateService;
use App\Services\RegionDepthDungeonService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RegionDepthDungeonController extends Controller
{
    public function show(string $dungeonKey)
    {
        $character = Auth::user()?->currentCharacter();
        abort_unless($character, 404);
        $service = app(RegionDepthDungeonService::class);
        $definition = $service->definition($dungeonKey);
        abort_if($definition === [], 404);

        return view('region-depth-dungeons.show', [
            'character' => $character,
            'dungeonKey' => $dungeonKey,
            'definition' => $definition,
            'payload' => $service->payload($character, $dungeonKey),
            'area' => $service->areaFor($dungeonKey),
            'state' => app(ExplorationStateService::class)->currentFor($character),
        ]);
    }

    public function enter(Request $request, string $dungeonKey)
    {
        $character = Auth::user()?->currentCharacter();
        abort_unless($character, 404);
        $service = app(RegionDepthDungeonService::class);
        try {
            $service->enter($character, $dungeonKey);
        } catch (\RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }
        return redirect()->route('region-depth-dungeons.show', ['dungeonKey' => $dungeonKey])->with('message', $service->definition($dungeonKey)['name'] . 'へ足を踏み入れた。');
    }

    public function returnToTown(Request $request, string $dungeonKey)
    {
        $character = Auth::user()?->currentCharacter();
        abort_unless($character, 404);
        $service = app(RegionDepthDungeonService::class);
        $run = $service->activeRun($character);
        if (!$run || $run->dungeon_key !== $dungeonKey) {
            return redirect()->route('region-depth-dungeons.show', ['dungeonKey' => $dungeonKey]);
        }
        $service->finalize($character, 'returned');
        app(ExplorationStateService::class)->reset($character);
        return redirect()->route('home', ['skip_resume' => 1])->with('message', $service->definition($dungeonKey)['name'] . 'から帰還した。');
    }
}
