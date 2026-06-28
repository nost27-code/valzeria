<?php

namespace App\Http\Controllers;

use App\Models\NpcProcurementRequest;
use App\Models\NpcProcurementRequestMaterial;
use App\Services\NpcProcurementRequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NpcProcurementRequestController extends Controller
{
    public function index(NpcProcurementRequestService $service)
    {
        $character = Auth::user()->currentCharacter();
        if (! $character) {
            return redirect()->route('home')->with('error', 'キャラクターが見つかりません。');
        }

        $requests = $service->getActiveRequests($character);

        return view('market.npc-requests.index', compact('character', 'requests'));
    }

    public function show(NpcProcurementRequest $npcProcurementRequest, NpcProcurementRequestService $service)
    {
        $character = Auth::user()->currentCharacter();
        if (! $character) {
            return redirect()->route('home')->with('error', 'キャラクターが見つかりません。');
        }

        $npcProcurementRequest->load(['materials.material', 'city', 'npc']);
        $service->attachDeliveryContext(collect([$npcProcurementRequest]), $character);

        return view('market.npc-requests.show', [
            'character' => $character,
            'request' => $npcProcurementRequest,
        ]);
    }

    public function deliver(Request $httpRequest, NpcProcurementRequestMaterial $requestMaterial, NpcProcurementRequestService $service)
    {
        $character = Auth::user()->currentCharacter();
        if (! $character) {
            return redirect()->route('home')->with('error', 'キャラクターが見つかりません。');
        }

        $validated = $httpRequest->validate([
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $delivery = $service->deliver($character, $requestMaterial->id, (int) $validated['quantity']);

        return redirect()
            ->back()
            ->with('status', "{$delivery->material?->displayName()} x{$delivery->quantity} を納品しました。報酬 " . number_format((int) $delivery->reward_gold) . 'G を獲得しました。');
    }
}
