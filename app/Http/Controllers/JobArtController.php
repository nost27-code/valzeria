<?php

namespace App\Http\Controllers;

use App\Services\JobArtService;
use App\Services\CharacterStatusService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class JobArtController extends Controller
{
    public function index(Request $request, JobArtService $jobArtService)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('character.select');
        }

        $filter = (string) $request->query('filter', 'available');
        $availableArts = $jobArtService->availableArts($character, 'pve');
        $selectedSlots = $jobArtService->selectedSlots($character, 'pve');
        $selectedSkills = $selectedSlots->pluck('skill')->filter()->values();
        $selectedSlotBySkill = $selectedSlots
            ->mapWithKeys(fn ($slot): array => [(int) $slot->skill_id => (int) $slot->slot_no]);
        $stats = app(CharacterStatusService::class)->getFinalStats($character);

        session([$jobArtService->setupSeenSessionKey($character) => $jobArtService->setupSignature($character, $availableArts, $selectedSlots)]);

        return view('job-arts.index', [
            'character' => $character,
            'maxSp' => max(0, (int) ($stats['max_mp'] ?? $character->mp_base ?? 0)),
            'availableArts' => $availableArts,
            'allAvailableArts' => $availableArts,
            'selectedSlots' => $selectedSlots,
            'selectedSkills' => $selectedSkills,
            'selectedSlotBySkill' => $selectedSlotBySkill,
            'totalCost' => $jobArtService->totalCost($selectedSkills),
            'filter' => $filter,
            'activationPolicy' => (string) ($character->job_art_activation_policy ?: 'normal'),
            'activationPolicyLabels' => $jobArtService->activationPolicyLabels(),
            'activationPolicyDescriptions' => $jobArtService->activationPolicyDescriptions(),
        ]);
    }

    public function set(Request $request, JobArtService $jobArtService)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('character.select');
        }

        $slots = [
            1 => $request->input('slot_1'),
            2 => $request->input('slot_2'),
            3 => $request->input('slot_3'),
        ];

        try {
            $jobArtService->saveSlots($character, $slots);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()->route('job-arts.index')->with('message', '奥義セットを保存しました。');
    }

    public function assign(Request $request, JobArtService $jobArtService)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('character.select');
        }

        $data = $request->validate([
            'skill_id' => ['required', 'integer'],
            'slot_no' => ['nullable', 'integer', 'min:1', 'max:3'],
            'filter' => ['nullable', 'string'],
        ]);

        try {
            $jobArtService->assignToSlot(
                $character,
                (int) $data['skill_id'],
                isset($data['slot_no']) && (int) $data['slot_no'] > 0 ? (int) $data['slot_no'] : null
            );
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => collect($e->errors())->flatten()->first() ?: '奥義スロットを更新できませんでした。',
                    'errors' => $e->errors(),
                ], 422);
            }

            return back()->withErrors($e->errors())->withInput();
        }

        if ($request->expectsJson()) {
            $selectedSlots = $jobArtService->selectedSlots($character->fresh(), 'pve');
            $selectedSkills = $selectedSlots->pluck('skill')->filter()->values();

            return response()->json([
                'message' => '奥義スロットを更新しました。',
                'total_cost' => $jobArtService->totalCost($selectedSkills),
                'selected_slot_by_skill' => $selectedSlots
                    ->mapWithKeys(fn ($slot): array => [(int) $slot->skill_id => (int) $slot->slot_no])
                    ->all(),
            ]);
        }

        return redirect()
            ->route('job-arts.index', ['filter' => $data['filter'] ?? 'available'])
            ->with('message', '奥義スロットを更新しました。');
    }

    public function policy(Request $request, JobArtService $jobArtService)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('character.select');
        }

        $data = $request->validate([
            'activation_policy' => ['required', 'string'],
            'filter' => ['nullable', 'string'],
        ]);

        try {
            $jobArtService->saveActivationPolicy($character, (string) $data['activation_policy']);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()
            ->route('job-arts.index', ['filter' => $data['filter'] ?? 'available'])
            ->with('message', '奥義発動方針を保存しました。');
    }
}
