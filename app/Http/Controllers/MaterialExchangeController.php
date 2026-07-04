<?php

namespace App\Http\Controllers;

use App\Services\MaterialExchangeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

class MaterialExchangeController extends Controller
{
    public function __construct(private MaterialExchangeService $materialExchangeService)
    {
    }

    public function index()
    {
        $character = Auth::user()->currentCharacter();
        $currentCity = $character->currentCity;
        $recipes = $this->materialExchangeService->recipes($character);

        return view('material-exchange.index', compact('character', 'currentCity', 'recipes'));
    }

    public function exchange(Request $request)
    {
        $validated = $request->validate([
            'recipe_id' => 'required|string|max:512',
            'quantity' => 'nullable|integer|min:1|max:500',
        ]);

        $character = Auth::user()->currentCharacter();

        try {
            $result = $this->materialExchangeService->exchange(
                $character,
                $validated['recipe_id'],
                (int) ($validated['quantity'] ?? 1)
            );
        } catch (RuntimeException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }

            return back()->with('error', $e->getMessage());
        }

        if ($request->expectsJson()) {
            $recipes = $this->materialExchangeService->recipes($character);

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'recipe_count' => count($recipes),
                'recipes_html' => view('material-exchange.partials.recipe-list', compact('recipes'))->render(),
            ]);
        }

        return redirect()->route('material-exchange.index')->with('status', $result['message']);
    }

    public function bulkExchange(Request $request)
    {
        $validated = $request->validate([
            'recipe_ids' => 'required|array|min:1|max:50',
            'recipe_ids.*' => 'required|string|max:512',
            'quantities' => 'nullable|array',
            'quantities.*' => 'nullable|integer|min:1|max:500',
        ]);

        $character = Auth::user()->currentCharacter();

        try {
            $result = $this->materialExchangeService->exchangeMany(
                $character,
                $validated['recipe_ids'],
                $validated['quantities'] ?? []
            );
        } catch (RuntimeException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }

            return back()->with('error', $e->getMessage());
        }

        if ($request->expectsJson()) {
            $recipes = $this->materialExchangeService->recipes($character);

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'recipe_count' => count($recipes),
                'recipes_html' => view('material-exchange.partials.recipe-list', compact('recipes'))->render(),
            ]);
        }

        return redirect()->route('material-exchange.index')->with('status', $result['message']);
    }
}
