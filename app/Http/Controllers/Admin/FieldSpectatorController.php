<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Field\ValzeriaFieldService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FieldSpectatorController extends Controller
{
    public function __construct(private readonly ValzeriaFieldService $field) {}

    public function show(): View
    {
        $world = $this->field->worldDefinition(false);
        $firstCityId = (int) (collect($world['cities'])->first()['id'] ?? 1);
        [$plane, $x, $y] = $this->field->spawnPoint($firstCityId);

        return view('field.valzeria', [
            'world' => $world,
            'player' => [
                'id' => 0,
                'name' => '管理者観察',
                'level' => 1,
                'icon' => '',
                'hidden' => true,
                'current_city_id' => $firstCityId,
                'unlocked_city_ids' => collect($world['cities'])->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                'enterable_area_ids' => collect($world['entrances'])->pluck('area_id')->map(fn ($id): int => (int) $id)->all(),
                'position' => ['plane' => $plane, 'x' => $x, 'y' => $y, 'facing' => 0],
                'vitals' => null,
                'claimed_spots' => [],
            ],
            'nationCanBuild' => false,
            'spectator' => true,
        ]);
    }

    public function presence(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plane' => ['required', 'string', 'in:land,sky'],
        ]);
        $all = $this->field->activePlayers();
        $plane = $data['plane'];

        return response()->json([
            'players' => $all->where('plane', $plane)->values()->all(),
            'all_players' => $all->values()->all(),
            'summary' => [
                'total' => $all->count(),
                'land' => $all->where('plane', ValzeriaFieldService::PLANE_LAND)->count(),
                'sky' => $all->where('plane', ValzeriaFieldService::PLANE_SKY)->count(),
            ],
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }
}
