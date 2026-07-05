<?php

namespace App\Http\Controllers;

use App\Services\CharacterProfileService;
use App\Services\SupportPassService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function edit(CharacterProfileService $profileService, SupportPassService $supportPassService)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('character.select');
        }

        $cardBackgrounds = $profileService->ownedAdventurerCardBackgrounds($character);
        $cardFrames = $profileService->ownedAdventurerCardFrames($character);
        $avatarFrames = $profileService->ownedAdventurerAvatarFrames($character);
        $valmonCases = $profileService->ownedValmonCases($character);

        return view('profile.edit', [
            'character' => $character,
            'cardBackgrounds' => $cardBackgrounds,
            'cardFrames' => $cardFrames,
            'avatarFrames' => $avatarFrames,
            'valmonCases' => $valmonCases,
            'selectedCardBackground' => $profileService->selectedAdventurerCardBackground($character, $character->profile_card_background),
            'selectedCardFrame' => $profileService->selectedAdventurerCardFrame($character, $character->profile_card_frame),
            'selectedAvatarFrame' => $profileService->selectedAdventurerAvatarFrame($character, $character->profile_avatar_frame),
            'selectedValmonCase' => $profileService->selectedValmonCase($character, $character->profile_valmon_case),
            'cardSkinOptions' => $supportPassService->cardSkinOptions($character),
            'selectedCardSkin' => $supportPassService->selectedCardSkin($character->user),
            'displayedCardSkin' => $supportPassService->displayedCardSkin($character->user),
            'supportPassStatus' => $supportPassService->statusForCharacter($character),
        ]);
    }

    public function update(Request $request, CharacterProfileService $profileService, SupportPassService $supportPassService)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('character.select');
        }

        $availableCardBackgrounds = collect($profileService->ownedAdventurerCardBackgrounds($character))->pluck('path')->all();
        $availableCardFrames = collect($profileService->ownedAdventurerCardFrames($character))->pluck('path')->all();
        $availableAvatarFrames = collect($profileService->ownedAdventurerAvatarFrames($character))->pluck('path')->all();
        $availableValmonCases = collect($profileService->ownedValmonCases($character))->pluck('path')->all();
        $rules = [
            'profile_comment' => ['nullable', 'string', 'max:160'],
            'profile_card_background' => ['required', 'string', Rule::in($availableCardBackgrounds)],
            'profile_card_frame' => ['required', 'string', Rule::in($availableCardFrames)],
            'profile_avatar_frame' => ['required', 'string', Rule::in($availableAvatarFrames)],
            'profile_valmon_case' => ['required', 'string', Rule::in($availableValmonCases)],
        ];

        if ($supportPassService->storageReady()) {
            $rules['selected_card_skin'] = ['required', 'string', Rule::in([SupportPassService::CARD_SKIN_DEFAULT, SupportPassService::CARD_SKIN_SUPPORT_PASS])];
        }

        $validated = $request->validate($rules);

        try {
            $selectedCardSkin = $supportPassService->storageReady()
                ? $supportPassService->normalizeSelectableCardSkin($character, $validated['selected_card_skin'])
                : null;
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['selected_card_skin' => $e->getMessage()])->withInput();
        }

        $character->profile_comment = trim((string) ($validated['profile_comment'] ?? '')) ?: null;
        $character->profile_card_background = $profileService->selectedAdventurerCardBackground($character, $validated['profile_card_background']);
        $character->profile_card_frame = $profileService->selectedAdventurerCardFrame($character, $validated['profile_card_frame']);
        $character->profile_avatar_frame = $profileService->selectedAdventurerAvatarFrame($character, $validated['profile_avatar_frame']);
        $character->profile_valmon_case = $profileService->selectedValmonCase($character, $validated['profile_valmon_case']);
        $character->save();
        if ($selectedCardSkin !== null) {
            $character->user->forceFill(['selected_card_skin' => $selectedCardSkin])->save();
        }
        Cache::forget('town_ranking_boards_v3');

        return redirect()->route('profile.edit')->with('message', 'プロフィールを更新しました。');
    }

    public function compressFrameMaterial(Request $request)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('character.select');
        }

        return redirect()->route('profile.edit')->with('message', 'プロフィール枠機能は現在停止中です。');
    }

    public function unlockFrame(Request $request)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('character.select');
        }

        return redirect()->route('profile.edit')->with('message', 'プロフィール枠機能は現在停止中です。');
    }
}
