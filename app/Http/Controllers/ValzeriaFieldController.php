<?php

namespace App\Http\Controllers;

use App\Models\Character;
use App\Services\Field\ValzeriaFieldService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class ValzeriaFieldController extends Controller
{
    public function __construct(private readonly ValzeriaFieldService $field) {}

    public function show(): View
    {
        abort_unless($this->field->enabled(), 404);

        $character = $this->character();
        abort_unless($character, 403);

        return view('field.valzeria', [
            'world' => $this->field->worldDefinition(false),
            'player' => $this->field->characterState($character, false),
            'nationCanBuild' => false,
            'walkingOnly' => true,
            'fieldNotice' => '宝箱などは一切ありません。開発中のため、急遽メンテナンスに入る場合があります。',
        ]);
    }

    public function sync(Request $request): JsonResponse
    {
        abort_unless($this->field->enabled(), 404);

        $character = $this->character();
        abort_unless($character, 403);

        $data = $request->validate([
            'plane' => ['required', 'string', 'in:land,sky'],
            'x' => ['required', 'integer', 'min:0'],
            'y' => ['required', 'integer', 'min:0'],
            'facing' => ['nullable', 'integer', 'min:0', 'max:3'],
        ]);
        if (! $this->field->inBounds($data['plane'], (int) $data['x'], (int) $data['y'])) {
            return response()->json(['message' => 'その場所には行けません。'], 422);
        }

        $position = $this->field->savePosition(
            $character,
            $data['plane'],
            (int) $data['x'],
            (int) $data['y'],
            (int) ($data['facing'] ?? 0),
        );

        return response()->json([
            'players' => $this->field->nearby($character, $position),
            'messages' => [],
            'chat' => [],
            'zone' => null,
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    private function character(): ?Character
    {
        return Auth::user()?->currentCharacter();
    }
}
