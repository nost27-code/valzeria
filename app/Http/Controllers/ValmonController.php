<?php

namespace App\Http\Controllers;

use App\Models\CharacterItem;
use App\Models\CharacterMaterial;
use App\Models\PlayerValmon;
use App\Models\ValmonMaster;
use App\Services\CharacterProfileService;
use App\Services\ValmonService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ValmonController extends Controller
{
    public function starter(ValmonService $service)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('character.select');
        }

        if (!$service->needsStarter($character)) {
            return redirect()->route('valmons.index');
        }

        return view('valmons.starter', [
            'starters' => $service->starters(),
        ]);
    }

    public function chooseStarter(Request $request, ValmonService $service)
    {
        $request->validate(['valmon_master_id' => 'required|integer']);

        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('character.select');
        }

        $result = $service->chooseStarter($character, (int) $request->input('valmon_master_id'));

        return redirect()
            ->route($result['success'] ? 'home' : 'valmons.starter')
            ->with($result['success'] ? 'status' : 'error', $result['message']);
    }

    public function index(ValmonService $service, CharacterProfileService $profileService)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('character.select');
        }

        $valmons = PlayerValmon::with('master')
            ->where('character_id', $character->id)
            ->orderByDesc('is_partner')
            ->orderByDesc('level')
            ->orderBy('id')
            ->get()
            ->each(function (PlayerValmon $valmon) use ($service) {
                $valmon->next_level_remaining = $service->nextLevelRemaining($valmon);
                $valmon->is_max_level = (int) $valmon->level >= ValmonService::MAX_LEVEL;
                $valmon->role_label = $service->roleLabel($valmon);
                $valmon->effect_summary = $service->effectSummary($valmon);
            });

        $ownedMasterIds = $valmons->pluck('valmon_master_id')->unique();
        $dex = ValmonMaster::where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($master) => [
                'master' => $master,
                'owned' => $ownedMasterIds->contains($master->id),
            ]);

        $partner = $valmons->firstWhere('is_partner', true);
        $materials = CharacterMaterial::with('material')
            ->where('character_id', $character->id)
            ->where('quantity', '>', 0)
            ->get()
            ->map(function (CharacterMaterial $row) use ($service) {
                $row->feed_exp = $service->materialFeedExp($row->material);
                return $row;
            })
            ->filter(fn ($row) => $row->feed_exp > 0)
            ->sortByDesc('feed_exp')
            ->values();

        $equipment = CharacterItem::with('item')
            ->where('character_id', $character->id)
            ->whereHas('item', fn ($query) => $query->whereIn('type', ['weapon', 'armor', 'accessory']))
            ->get()
            ->map(function (CharacterItem $row) use ($service) {
                $row->feed_exp = $service->equipmentFeedExp($row);
                return $row;
            })
            ->filter(fn ($row) => $row->feed_exp > 0)
            ->sortByDesc('feed_exp')
            ->values();

        $activeEgg = $character->valmonEggs()
            ->where('is_hatched', false)
            ->where('is_lost', false)
            ->whereNull('stored_at')
            ->first();

        $ranchBackgrounds = $profileService->ownedRanchBackgrounds($character);
        $selectedRanchBackground = $profileService->selectedRanchBackground($character, $character->profile_ranch_background);

        return view('valmons.index', compact(
            'character',
            'valmons',
            'dex',
            'partner',
            'materials',
            'equipment',
            'activeEgg',
            'ranchBackgrounds',
            'selectedRanchBackground'
        ));
    }

    public function updateBackground(Request $request, CharacterProfileService $profileService)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('character.select');
        }

        $ownedBackgrounds = collect($profileService->ownedRanchBackgrounds($character))->pluck('path')->all();
        $validated = $request->validate([
            'profile_ranch_background' => ['required', 'string', Rule::in($ownedBackgrounds)],
        ]);

        $character->profile_ranch_background = $profileService->selectedRanchBackground($character, $validated['profile_ranch_background']);
        $character->save();

        return back()->with('status', '牧場背景を変更しました。');
    }

    public function updateNickname(Request $request, PlayerValmon $valmon)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('character.select');
        }

        abort_unless((int) $valmon->character_id === (int) $character->id, 403);

        $request->merge([
            'nickname' => $this->trimDisplayName((string) $request->input('nickname', '')),
        ]);

        $validated = $request->validateWithBag('renameValmon' . $valmon->id, [
            'nickname' => ['required', 'string', 'min:1', 'max:8'],
        ], [
            'nickname.required' => 'ヴァルモン名を入力してください。',
            'nickname.max' => 'ヴァルモン名は8文字以内で入力してください。',
        ], [
            'nickname' => 'ヴァルモン名',
        ]);

        $valmon->nickname = $validated['nickname'];
        $valmon->save();

        return back()->with('status', $valmon->displayName() . 'に命名しました。');
    }

    public function setPartner(PlayerValmon $valmon, ValmonService $service)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('character.select');
        }

        $result = $service->setPartner($character, $valmon);

        return back()->with($result['success'] ? 'status' : 'error', $result['message']);
    }

    public function feedMaterial(Request $request, PlayerValmon $valmon, CharacterMaterial $characterMaterial, ValmonService $service)
    {
        $request->validateWithBag('feedMaterial' . $characterMaterial->id, [
            'quantity' => ['required', 'integer', 'min:1', 'max:' . max(1, (int) $characterMaterial->quantity)],
        ], [], [
            'quantity' => '個数',
        ]);

        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('character.select');
        }

        $result = $service->feedMaterial($character, $valmon, $characterMaterial, (int) $request->input('quantity'));

        return back()
            ->with($result['success'] ? 'status' : 'error', $result['message'])
            ->with('valmon_active_tab', 'feed')
            ->with('valmon_feed_kind', 'material');
    }

    public function feedEquipment(PlayerValmon $valmon, CharacterItem $characterItem, ValmonService $service)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('character.select');
        }

        $result = $service->feedEquipment($character, $valmon, $characterItem);

        return back()
            ->with($result['success'] ? 'status' : 'error', $result['message'])
            ->with('valmon_active_tab', 'feed')
            ->with('valmon_feed_kind', 'equipment');
    }

    public function feedEquipmentBulk(Request $request, PlayerValmon $valmon, ValmonService $service)
    {
        $request->validate([
            'character_item_ids' => 'required|array|min:1|max:100',
            'character_item_ids.*' => 'integer',
        ]);

        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('character.select');
        }

        $result = $service->feedEquipmentBulk($character, $valmon, $request->input('character_item_ids', []));

        return back()
            ->with($result['success'] ? 'status' : 'error', $result['message'])
            ->with('valmon_active_tab', 'feed')
            ->with('valmon_feed_kind', 'equipment');
    }

    private function trimDisplayName(string $value): string
    {
        return preg_replace('/\A[\s　]+|[\s　]+\z/u', '', $value) ?? '';
    }
}
