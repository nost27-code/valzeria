<?php

namespace App\Http\Controllers;

use App\Services\CharacterProfileService;
use App\Services\ProfileFrameUnlockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function edit(CharacterProfileService $profileService, ProfileFrameUnlockService $frameUnlockService)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('character.select');
        }

        $backgrounds = $profileService->ownedRanchBackgrounds($character);

        return view('profile.edit', [
            'character' => $character,
            'backgrounds' => $backgrounds,
            'selectedBackground' => $profileService->selectedRanchBackground($character, $character->profile_ranch_background),
            'frameThemes' => $profileService->availableFrameThemes($character),
            'selectedFrameTheme' => $profileService->selectedFrameThemeFor($character, $character->profile_frame_theme),
            'frameUnlocks' => $frameUnlockService->progress($character, $profileService->frameThemes()),
        ]);
    }

    public function update(Request $request, CharacterProfileService $profileService)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('character.select');
        }

        $availableBackgrounds = collect($profileService->ownedRanchBackgrounds($character))->pluck('path')->all();
        $availableFrameThemes = collect($profileService->availableFrameThemes($character))->pluck('code')->all();
        $validated = $request->validate([
            'profile_comment' => ['nullable', 'string', 'max:160'],
            'profile_ranch_background' => ['required', 'string', Rule::in($availableBackgrounds)],
            'profile_frame_theme' => ['required', 'string', Rule::in($availableFrameThemes)],
        ]);

        $character->profile_comment = trim((string) ($validated['profile_comment'] ?? '')) ?: null;
        $character->profile_ranch_background = $profileService->selectedRanchBackground($character, $validated['profile_ranch_background']);
        $character->profile_frame_theme = $profileService->selectedFrameThemeFor($character, $validated['profile_frame_theme']);
        $character->save();

        return redirect()->route('profile.edit')->with('message', 'プロフィールを更新しました。');
    }

    public function compressFrameMaterial(Request $request, ProfileFrameUnlockService $frameUnlockService)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('character.select');
        }

        $validated = $request->validate([
            'profile_frame_theme' => ['required', 'string'],
        ]);

        $frameUnlockService->compress($character, (string) $validated['profile_frame_theme']);

        return redirect()->route('profile.edit')->with('message', '地方限定素材10個を装飾片1個にしました。');
    }

    public function unlockFrame(Request $request, ProfileFrameUnlockService $frameUnlockService)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('character.select');
        }

        $validated = $request->validate([
            'profile_frame_theme' => ['required', 'string'],
        ]);

        $frameUnlockService->unlock($character, (string) $validated['profile_frame_theme']);

        return redirect()->route('profile.edit')->with('message', 'プロフィール枠を解放しました。');
    }
}
