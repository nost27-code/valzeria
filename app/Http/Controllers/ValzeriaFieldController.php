<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\Character;
use App\Models\CharacterFieldPosition;
use App\Models\City;
use App\Services\ExplorationStateService;
use App\Services\Field\FieldChatService;
use App\Services\Field\FieldEncounterService;
use App\Services\Field\ValzeriaFieldService;
use App\Services\PlayerLifecycleEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class ValzeriaFieldController extends Controller
{
    public function __construct(
        private readonly ValzeriaFieldService $field,
        private readonly FieldEncounterService $encounters,
        private readonly FieldChatService $chat,
    ) {}

    public function show(): View
    {
        abort_unless($this->field->enabled(), 404);

        $character = $this->character();
        abort_unless($character, 403);

        return view('field.valzeria', [
            'world' => $this->field->worldDefinition(true, false),
            'player' => $this->field->characterState($character, true, false),
            'nationCanBuild' => false,
            'walkingOnly' => false,
            'fieldFeatures' => [
                'combat' => true,
                'facilities' => true,
                'chat' => true,
                'gathering' => false,
                'areas' => false,
                'teleport' => false,
                'nations' => false,
            ],
            'fieldNotice' => '宝箱・採取はありません。魔物との戦闘・街の施設・チャットを利用できます。開発中のため、急遽メンテナンスに入る場合があります。',
        ]);
    }

    public function sync(Request $request): JsonResponse
    {
        abort_unless($this->field->enabled(), 404);

        $character = $this->character();
        abort_unless($character, 403);

        $position = $this->storePosition($request, $character);
        if (! $position) {
            return response()->json(['message' => 'その場所には行けません。'], 422);
        }

        $zone = $this->chat->zoneAt($position->plane, $position->x, $position->y);

        return response()->json([
            'players' => $this->field->nearby($character, $position),
            'messages' => $this->encounters->returnIfInTown($character, $position->plane, $position->x, $position->y),
            'chat' => $this->chat->heardAt(
                $position->plane,
                $position->x,
                $position->y,
                max(0, $request->integer('chat_since')),
                $zone,
            ),
            'zone' => $zone,
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    public function say(Request $request): JsonResponse
    {
        abort_unless($this->field->enabled(), 404);
        $character = $this->character();
        abort_unless($character, 403);

        $position = $this->storePosition($request, $character);
        if (! $position) {
            return response()->json(['message' => 'その場所には行けません。'], 422);
        }

        $body = mb_substr((string) $request->input('body', ''), 0, 500);
        $scope = $request->input('scope') === FieldChatService::SCOPE_TOWN
            ? FieldChatService::SCOPE_TOWN
            : FieldChatService::SCOPE_AREA;
        $said = $this->chat->say($character, $position->plane, $position->x, $position->y, $body, $scope);
        if (isset($said['error'])) {
            return response()->json(['message' => $said['error']], 422);
        }

        return response()->json(['said' => $said]);
    }

    public function battle(Request $request, Area $area): JsonResponse
    {
        abort_unless($this->field->enabled(), 404);
        $character = $this->character();
        abort_unless($character, 403);

        $position = $this->storePosition($request, $character);
        if (! $position) {
            return response()->json(['ok' => false, 'message' => 'その場所には行けません。'], 422);
        }

        return response()->json($this->encounters->fight($character, $area, $position->plane, $position->x, $position->y));
    }

    public function enterFacility(Request $request, City $city, string $slug): RedirectResponse
    {
        abort_unless($this->field->enabled(), 404);
        $character = $this->character();
        abort_unless($character, 403);

        $definition = $this->field->cityDefinitions()->get((int) $city->id);
        $facility = config("valzeria_field.facilities.{$slug}");
        $available = $definition && $facility && collect($definition['facilities'])->contains('slug', $slug);
        if (! $available) {
            return redirect()->route('field.show')->with('error', 'その施設はありません。');
        }

        $position = $this->storePosition($request, $character);
        if (! $position) {
            return redirect()->route('field.show')->with('error', 'その場所には行けません。');
        }
        if (! $this->field->canUseCity($character, (int) $city->id)) {
            return redirect()->route('field.show')->with('error', "まだ{$city->name}には入れません。");
        }
        if (! $this->field->isInsideCity($character, (int) $city->id)) {
            return redirect()->route('field.show')->with('error', '施設から離れすぎています。');
        }

        $messages = $this->encounters->returnIfInTown($character, $position->plane, $position->x, $position->y);
        $this->arriveAt($character, $city);
        session(['current_location' => 'town']);

        $redirect = redirect()->route($facility['route']);

        return $messages ? $redirect->with('message', implode(' ', $messages)) : $redirect;
    }

    private function character(): ?Character
    {
        return Auth::user()?->currentCharacter();
    }

    private function storePosition(Request $request, Character $character): ?CharacterFieldPosition
    {
        $data = $request->validate([
            'plane' => ['required', 'string', 'in:land,sky'],
            'x' => ['required', 'integer', 'min:0'],
            'y' => ['required', 'integer', 'min:0'],
            'facing' => ['nullable', 'integer', 'min:0', 'max:3'],
        ]);
        if (! $this->field->inBounds($data['plane'], (int) $data['x'], (int) $data['y'])) {
            return null;
        }

        return $this->field->savePosition(
            $character,
            $data['plane'],
            (int) $data['x'],
            (int) $data['y'],
            (int) ($data['facing'] ?? 0),
        );
    }

    private function arriveAt(Character $character, City $city): void
    {
        if ((int) $character->current_city_id === (int) $city->id) {
            return;
        }

        $character->current_city_id = $city->id;
        $character->save();
        app(PlayerLifecycleEventService::class)->recordCityReached($character, $city);
        app(ExplorationStateService::class)->reset($character);
    }
}
