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
        $normalAvailableArts = $jobArtService->availableArts($character, 'pve');
        $bossAvailableArts = $jobArtService->availableArts($character, 'boss');
        $availableArts = $normalAvailableArts
            ->merge($bossAvailableArts)
            ->unique('id')
            ->values();
        $selectedSlotsByContext = [
            'normal' => $jobArtService->selectedSlots($character, 'pve', 'normal'),
            'boss' => $jobArtService->selectedSlots($character, 'boss', 'boss'),
        ];
        $selectedSlots = $selectedSlotsByContext['normal']->merge($selectedSlotsByContext['boss']);
        $selectedSkillsByContext = [
            'normal' => $selectedSlotsByContext['normal']->pluck('skill')->filter()->values(),
            'boss' => $selectedSlotsByContext['boss']->pluck('skill')->filter()->values(),
        ];
        $selectedSlotBySkillByContext = [
            'normal' => $selectedSlotsByContext['normal']
                ->mapWithKeys(fn ($slot): array => [(int) $slot->skill_id => (int) $slot->slot_no]),
            'boss' => $selectedSlotsByContext['boss']
                ->mapWithKeys(fn ($slot): array => [(int) $slot->skill_id => (int) $slot->slot_no]),
        ];
        $stats = app(CharacterStatusService::class)->getFinalStats($character);

        session([$jobArtService->setupSeenSessionKey($character) => $jobArtService->setupSignature($character, $availableArts, $selectedSlots)]);

        return view('job-arts.index', [
            'character' => $character,
            'maxSp' => max(0, (int) ($stats['max_mp'] ?? $character->mp_base ?? 0)),
            'availableArts' => $availableArts,
            'allAvailableArts' => $availableArts,
            'availableArtsByContext' => [
                'normal' => $normalAvailableArts,
                'boss' => $bossAvailableArts,
            ],
            'selectedSlots' => $selectedSlotsByContext['normal'],
            'selectedSlotsByContext' => $selectedSlotsByContext,
            'selectedSkillsByContext' => $selectedSkillsByContext,
            'selectedSlotBySkill' => $selectedSlotBySkillByContext['normal'],
            'selectedSlotBySkillByContext' => $selectedSlotBySkillByContext,
            'totalCost' => $jobArtService->totalCost($selectedSkillsByContext['normal']),
            'totalCostByContext' => [
                'normal' => $jobArtService->totalCost($selectedSkillsByContext['normal']),
                'boss' => $jobArtService->totalCost($selectedSkillsByContext['boss']),
            ],
            'slotContextLabels' => $jobArtService->slotContextLabels(),
            'slotContextDescriptions' => $jobArtService->slotContextDescriptions(),
            'filter' => $filter,
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

        try {
            foreach (JobArtService::SLOT_CONTEXTS as $slotContext) {
                $slots = [
                    1 => $request->input($slotContext . '_slot_1'),
                    2 => $request->input($slotContext . '_slot_2'),
                    3 => $request->input($slotContext . '_slot_3'),
                ];
                $policies = [
                    1 => $request->input($slotContext . '_policy_1'),
                    2 => $request->input($slotContext . '_policy_2'),
                    3 => $request->input($slotContext . '_policy_3'),
                ];
                $jobArtService->saveSlots(
                    $character,
                    $slots,
                    $slotContext,
                    $slotContext === 'boss' ? 'boss' : 'pve',
                    $policies
                );
            }
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
            'slot_context' => ['nullable', 'string'],
            'filter' => ['nullable', 'string'],
        ]);

        $slotContext = in_array((string) ($data['slot_context'] ?? 'normal'), JobArtService::SLOT_CONTEXTS, true)
            ? (string) ($data['slot_context'] ?? 'normal')
            : 'normal';

        try {
            $jobArtService->assignToSlot(
                $character,
                (int) $data['skill_id'],
                isset($data['slot_no']) && (int) $data['slot_no'] > 0 ? (int) $data['slot_no'] : null,
                $slotContext
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
            $selectedSlots = $jobArtService->selectedSlots(
                $character->fresh(),
                $slotContext === 'boss' ? 'boss' : 'pve',
                $slotContext
            );
            $selectedSkills = $selectedSlots->pluck('skill')->filter()->values();

            return response()->json([
                'message' => '奥義スロットを更新しました。',
                'total_cost' => $jobArtService->totalCost($selectedSkills),
                'slot_context' => $slotContext,
                'selected_slot_by_skill' => $selectedSlots
                    ->mapWithKeys(fn ($slot): array => [(int) $slot->skill_id => (int) $slot->slot_no])
                    ->all(),
            ]);
        }

        return redirect()
            ->route('job-arts.index', ['filter' => $data['filter'] ?? 'available'])
            ->with('message', '奥義スロットを更新しました。');
    }

    public function slotSet(Request $request, JobArtService $jobArtService)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('character.select');
        }

        $data = $request->validate([
            'slot_context' => ['required', 'string'],
            'slot_no' => ['required', 'integer', 'min:1', 'max:3'],
            'skill_id' => ['nullable', 'integer'],
            'activation_policy' => ['nullable', 'string'],
            'filter' => ['nullable', 'string'],
        ]);

        $slotContext = in_array((string) $data['slot_context'], JobArtService::SLOT_CONTEXTS, true)
            ? (string) $data['slot_context']
            : 'normal';

        try {
            $jobArtService->setSlot(
                $character,
                $slotContext,
                (int) $data['slot_no'],
                isset($data['skill_id']) ? (int) $data['skill_id'] : null,
                $data['activation_policy'] ?? null
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
            return response()->json([
                'message' => '奥義スロットを更新しました。',
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
