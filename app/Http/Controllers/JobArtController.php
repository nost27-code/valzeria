<?php

namespace App\Http\Controllers;

use App\Models\Character;
use App\Models\Skill;
use App\Services\JobArtService;
use App\Services\CharacterStatusService;
use App\Services\JobArtLineageCatalog;
use App\Services\JobArtV2BattleRules;
use App\Services\JobArtV2LoadoutPresenter;
use App\Services\JobArtV2LoadoutDiagnosisService;
use App\Services\JobArtV2LineageGuideCatalog;
use App\Services\JobArtV2ResourceCatalog;
use App\Services\JobArtV2SlotConditionCatalog;
use App\Services\JobArtV2SpCostCalculator;
use App\Services\JobArtV2StarterPresetService;
use App\Services\JobArtPresetService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class JobArtController extends Controller
{
    public function index(
        Request $request,
        JobArtService $jobArtService,
        JobArtV2SpCostCalculator $spCostCalculator,
        JobArtV2BattleRules $battleRules,
        JobArtV2LoadoutPresenter $loadoutPresenter,
        JobArtV2LoadoutDiagnosisService $loadoutDiagnosisService,
        JobArtV2LineageGuideCatalog $lineageGuideCatalog,
        JobArtV2ResourceCatalog $resourceCatalog,
        JobArtLineageCatalog $lineageCatalog,
        JobArtPresetService $presetService,
        JobArtV2StarterPresetService $starterPresetService,
    )
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('character.select');
        }

        $stats = app(CharacterStatusService::class)->getFinalStats($character);
        $maxSp = max(0, (int) ($stats['max_mp'] ?? $character->mp_base ?? 0));

        $filter = (string) $request->query('filter', 'available');
        $availableArtsByContext = [];
        $selectedSlotsByContext = [];
        foreach ($jobArtService->slotContexts() as $slotContext) {
            $availabilityContext = $jobArtService->availabilityContextForSlotContext($slotContext);
            $availableArtsByContext[$slotContext] = $jobArtService->availableArts($character, $availabilityContext);
            $this->decorateArtsForDisplay(
                $availableArtsByContext[$slotContext],
                $character,
                $maxSp,
                $spCostCalculator,
                $battleRules,
                $loadoutPresenter,
            );
            $selectedSlotsByContext[$slotContext] = $jobArtService->selectedSlots(
                $character,
                $availabilityContext,
                $slotContext,
                $availableArtsByContext[$slotContext],
            );
        }

        $availableArts = collect($availableArtsByContext)
            ->flatMap(fn ($arts) => $arts)
            ->unique('id')
            ->values();
        $selectedSlots = collect($selectedSlotsByContext)->flatMap(fn ($slots) => $slots)->values();
        $selectedSkillsByContext = collect($selectedSlotsByContext)
            ->map(fn ($slots) => $slots->pluck('skill')->filter()->values())
            ->all();
        $selectedSlotBySkillByContext = collect($selectedSlotsByContext)
            ->map(fn ($slots) => $slots->mapWithKeys(fn ($slot): array => [(int) $slot->skill_id => (int) $slot->slot_no]))
            ->all();
        $totalCostByContext = collect($selectedSkillsByContext)
            ->map(fn ($skills): int => $jobArtService->totalCost($skills))
            ->all();
        $currentJobId = $character->current_job_id !== null ? (int) $character->current_job_id : null;
        $activeLineagesByContext = collect($selectedSkillsByContext)
            ->map(fn ($skills): array => $this->activeLineagesForSkills(
                $currentJobId,
                $skills,
                $resourceCatalog,
                $lineageCatalog,
            ))
            ->all();
        $jobArtV2UiEnabled = $loadoutPresenter->enabledForCurrentJob($currentJobId);
        if ($jobArtV2UiEnabled && in_array($filter, ['current', 'inherited'], true)) {
            $filter = 'available';
        }
        $contextSpPolicies = $jobArtService->contextSpPolicies($character);
        $loadoutDiagnosesByContext = collect($jobArtService->slotContexts())
            ->mapWithKeys(fn (string $slotContext): array => [
                $slotContext => $loadoutDiagnosisService->diagnose(
                    $character,
                    $selectedSlotsByContext[$slotContext],
                    $slotContext,
                    $maxSp,
                    $contextSpPolicies[$slotContext] ?? 'aggressive',
                    $jobArtService->maxSlots(),
                    $jobArtService->maxCost(),
                ),
            ])
            ->all();
        $recommendedBattleStyles = $loadoutPresenter->recommendationsForCurrentJob($currentJobId, $availableArts);
        $jobArtPresetUiEnabled = $presetService->enabledFor($character);
        $jobArtPresets = $jobArtPresetUiEnabled
            ? $presetService->presetsForDisplay($character)
            : [];
        $jobArtStarterPresetCount = $starterPresetService->presetCountForDisplay($character);
        session([$jobArtService->setupSeenSessionKey($character) => $jobArtService->setupSignature($character, $availableArts, $selectedSlots)]);

        return view('job-arts.index', [
            'character' => $character,
            'maxSp' => $maxSp,
            'availableArts' => $availableArts,
            'allAvailableArts' => $availableArts,
            'availableArtsByContext' => $availableArtsByContext,
            'selectedSlots' => $selectedSlotsByContext['normal'],
            'selectedSlotsByContext' => $selectedSlotsByContext,
            'selectedSkillsByContext' => $selectedSkillsByContext,
            'selectedSlotBySkill' => $selectedSlotBySkillByContext['normal'],
            'selectedSlotBySkillByContext' => $selectedSlotBySkillByContext,
            'totalCost' => $jobArtService->totalCost($selectedSkillsByContext['normal']),
            'totalCostByContext' => $totalCostByContext,
            'slotContextLabels' => $jobArtService->slotContextLabels(),
            'slotContextDescriptions' => $this->slotContextDescriptions($jobArtService, $jobArtV2UiEnabled),
            'filter' => $filter,
            'activationPolicyLabels' => $jobArtService->activationPolicyLabels(),
            'activationPolicyDescriptions' => $this->activationPolicyDescriptions(
                $jobArtService,
                $battleRules,
                $currentJobId,
            ),
            'contextSpPolicies' => $contextSpPolicies,
            'loadoutDiagnosesByContext' => $loadoutDiagnosesByContext,
            'jobArtV2CardDetailsEnabled' => (bool) config('battle.job_art_v2.loadout_card_details', false),
            'slotConditionLabels' => $jobArtService->slotConditionLabels(),
            'maxSlots' => $jobArtService->maxSlots(),
            'maxCost' => $jobArtService->maxCost(),
            'currentJobId' => $currentJobId,
            'jobArtV2UiEnabled' => $jobArtV2UiEnabled,
            'recommendedBattleStyles' => $recommendedBattleStyles,
            'jobArtPresetUiEnabled' => $jobArtPresetUiEnabled,
            'jobArtPresets' => $jobArtPresets,
            'jobArtPresetLimit' => $presetService->limitFor($character),
            'jobArtStarterPresetCount' => $jobArtStarterPresetCount,
            'jobArtStarterPresetHighlighted' => $this->starterPresetHighlightActive(),
            'activeLineagesByContext' => $activeLineagesByContext,
            'currentLineageResourceGuide' => $starterPresetService->resourceGuideForDisplay($character),
            'lineageGuides' => $jobArtV2UiEnabled ? $lineageGuideCatalog->all() : [],
        ]);
    }

    public function starterPresets(
        Request $request,
        JobArtService $jobArtService,
        JobArtV2StarterPresetService $starterPresetService,
    ) {
        $character = Auth::user()->currentCharacter();
        if (! $character) {
            return response()->json(['message' => 'キャラクターを選択してください。'], 409);
        }

        $data = $request->validate([
            'slot_context' => ['required', 'string', Rule::in($jobArtService->slotContexts())],
        ]);
        $slotContext = (string) $data['slot_context'];
        $starterPresets = $starterPresetService->presetsForDisplay($character, $slotContext);
        if ($starterPresets === []) {
            return response()->json(['message' => '現在利用できる公式プリセットはありません。'], 404);
        }

        $slotContextLabel = ['normal' => '通常', 'boss' => 'ボス', 'pvp' => 'PvP'][$slotContext]
            ?? ($jobArtService->slotContextLabels()[$slotContext] ?? $slotContext);

        return response()->json([
            'html' => view('job-arts.partials.starter-preset-cards', compact(
                'starterPresets',
                'slotContext',
                'slotContextLabel',
            ))->render(),
        ]);
    }

    public function set(Request $request, JobArtService $jobArtService)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('character.select');
        }

        try {
            DB::transaction(function () use ($character, $jobArtService, $request): void {
                foreach ($jobArtService->slotContexts() as $slotContext) {
                    $slots = [];
                    $policies = [];
                    $conditions = [];
                    for ($slotNo = 1; $slotNo <= $jobArtService->maxSlots(); $slotNo++) {
                        $slots[$slotNo] = $request->input($slotContext . '_slot_' . $slotNo);
                        $policies[$slotNo] = $request->input($slotContext . '_policy_' . $slotNo);
                        $conditions[$slotNo] = $request->input($slotContext . '_condition_' . $slotNo);
                    }
                    $jobArtService->saveSlots(
                        $character,
                        $slots,
                        $slotContext,
                        $jobArtService->availabilityContextForSlotContext($slotContext),
                        $policies,
                        $conditions,
                    );
                }
            });
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()->route('job-arts.index')->with('message', $this->displayTerm($character) . 'セットを保存しました。');
    }

    public function assign(Request $request, JobArtService $jobArtService)
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('character.select');
        }

        $data = $request->validate([
            'skill_id' => ['required', 'integer'],
            'slot_no' => ['nullable', 'integer', 'min:1', 'max:' . $jobArtService->maxSlots()],
            'slot_context' => ['nullable', 'string', Rule::in($jobArtService->slotContexts())],
            'filter' => ['nullable', 'string'],
        ]);

        $slotContext = (string) ($data['slot_context'] ?? 'normal');

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
                    'message' => collect($e->errors())->flatten()->first() ?: $this->displayTerm($character) . 'スロットを更新できませんでした。',
                    'errors' => $e->errors(),
                ], 422);
            }

            return back()->withErrors($e->errors())->withInput();
        }

        if ($request->expectsJson()) {
            $selectedSlots = $jobArtService->selectedSlots(
                $character->fresh(),
                $jobArtService->availabilityContextForSlotContext($slotContext),
                $slotContext
            );
            $selectedSkills = $selectedSlots->pluck('skill')->filter()->values();

            return response()->json([
                'message' => $this->displayTerm($character) . 'スロットを更新しました。',
                'total_cost' => $jobArtService->totalCost($selectedSkills),
                'slot_context' => $slotContext,
                'selected_slot_by_skill' => $selectedSlots
                    ->mapWithKeys(fn ($slot): array => [(int) $slot->skill_id => (int) $slot->slot_no])
                    ->all(),
            ]);
        }

        return redirect()
            ->route('job-arts.index', ['filter' => $data['filter'] ?? 'available'])
            ->with('message', $this->displayTerm($character) . 'スロットを更新しました。');
    }

    public function slotSet(
        Request $request,
        JobArtService $jobArtService,
        JobArtV2SpCostCalculator $spCostCalculator,
        JobArtV2BattleRules $battleRules,
        JobArtV2LoadoutPresenter $loadoutPresenter,
        JobArtV2SlotConditionCatalog $slotConditionCatalog,
        JobArtV2LoadoutDiagnosisService $loadoutDiagnosisService,
        JobArtV2ResourceCatalog $resourceCatalog,
        JobArtLineageCatalog $lineageCatalog,
    )
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('character.select');
        }

        $data = $request->validate([
            'slot_context' => ['required', 'string', Rule::in($jobArtService->slotContexts())],
            'slot_no' => ['required', 'integer', 'min:1', 'max:' . $jobArtService->maxSlots()],
            'skill_id' => ['nullable', 'integer'],
            'activation_policy' => ['nullable', 'string'],
            'slot_condition' => ['nullable', 'string', Rule::in(array_keys($slotConditionCatalog->labels()))],
            'filter' => ['nullable', 'string'],
        ]);

        $slotContext = (string) $data['slot_context'];

        try {
            $jobArtService->setSlot(
                $character,
                $slotContext,
                (int) $data['slot_no'],
                isset($data['skill_id']) ? (int) $data['skill_id'] : null,
                $data['activation_policy'] ?? null,
                $data['slot_condition'] ?? null,
            );
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => collect($e->errors())->flatten()->first() ?: $this->displayTerm($character) . 'スロットを更新できませんでした。',
                    'errors' => $e->errors(),
                ], 422);
            }

            return back()->withErrors($e->errors())->withInput();
        }

        if ($request->expectsJson()) {
            $character = $character->fresh();
            $availabilityContext = $jobArtService->availabilityContextForSlotContext($slotContext);
            $availableArtsByContext = collect($jobArtService->slotContexts())
                ->mapWithKeys(fn (string $context): array => [
                    $context => $jobArtService->availableArts(
                        $character,
                        $jobArtService->availabilityContextForSlotContext($context)
                    ),
                ]);
            $allAvailableArts = $availableArtsByContext->flatMap(fn ($arts) => $arts)->unique('id')->values();
            $contextArts = $availableArtsByContext->get($slotContext, collect());
            $selectedSlots = $jobArtService->selectedSlots($character, $availabilityContext, $slotContext);
            $selectedSkills = $selectedSlots->pluck('skill')->filter()->values();
            $stats = app(CharacterStatusService::class)->getFinalStats($character);
            $maxSp = max(0, (int) ($stats['max_mp'] ?? $character->mp_base ?? 0));
            foreach ($availableArtsByContext as $arts) {
                $this->decorateArtsForDisplay(
                    $arts,
                    $character,
                    $maxSp,
                    $spCostCalculator,
                    $battleRules,
                    $loadoutPresenter,
                );
            }
            $contextTotalCost = $jobArtService->totalCost($selectedSkills);
            $currentJobId = $character->current_job_id !== null ? (int) $character->current_job_id : null;
            $jobArtV2UiEnabled = $loadoutPresenter->enabledForCurrentJob($currentJobId);

            $slotsHtml = '';
            for ($slotNo = 1; $slotNo <= $jobArtService->maxSlots(); $slotNo++) {
                $slotsHtml .= view('job-arts.partials.slot-card', [
                    'slotContext' => $slotContext,
                    'slotNo' => $slotNo,
                    'slot' => $selectedSlots->firstWhere('slot_no', $slotNo),
                    'contextArts' => $contextArts,
                    'allAvailableArts' => $allAvailableArts,
                    'maxSp' => $maxSp,
                    'activationPolicyLabels' => $jobArtService->activationPolicyLabels(),
                    'activationPolicyDescriptions' => $this->activationPolicyDescriptions(
                        $jobArtService,
                        $battleRules,
                        $character->current_job_id !== null ? (int) $character->current_job_id : null,
                    ),
                    'slotConditionLabels' => $jobArtService->slotConditionLabels(),
                    'contextTotalCost' => $contextTotalCost,
                    'maxCost' => $jobArtService->maxCost(),
                    'currentJobId' => $currentJobId,
                    'jobArtV2UiEnabled' => $jobArtV2UiEnabled,
                    'jobArtV2CardDetailsEnabled' => (bool) config('battle.job_art_v2.loadout_card_details', false),
                ])->render();
            }
            $diagnosis = $loadoutDiagnosisService->diagnose(
                $character,
                $selectedSlots,
                $slotContext,
                $maxSp,
                $jobArtService->contextSpPolicy($character, $slotContext),
                $jobArtService->maxSlots(),
                $jobArtService->maxCost(),
            );
            $activeLineages = $this->activeLineagesForSkills(
                $currentJobId,
                $selectedSkills,
                $resourceCatalog,
                $lineageCatalog,
            );

            return response()->json([
                'message' => $this->displayTerm($character) . 'スロットを更新しました。',
                'slot_context' => $slotContext,
                'total_cost' => $contextTotalCost,
                'slots_html' => $slotsHtml,
                'diagnosis_html' => view('job-arts.partials.loadout-diagnosis', compact('diagnosis'))->render(),
                'active_lineages_html' => view(
                    'job-arts.partials.active-lineages',
                    compact('activeLineages'),
                )->render(),
                'selected_slot_by_skill' => $selectedSlots
                    ->mapWithKeys(fn ($slot): array => [(int) $slot->skill_id => (int) $slot->slot_no])
                    ->all(),
            ]);
        }

        return redirect()
            ->route('job-arts.index', ['filter' => $data['filter'] ?? 'available'])
            ->with('message', $this->displayTerm($character) . 'スロットを更新しました。');
    }

    public function reorder(
        Request $request,
        JobArtService $jobArtService,
        JobArtV2LoadoutDiagnosisService $loadoutDiagnosisService,
    )
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('character.select');
        }

        $data = $request->validate([
            'slot_context' => ['required', 'string', Rule::in($jobArtService->slotContexts())],
            'ordered_skill_ids' => ['required', 'array', 'size:' . $jobArtService->maxSlots()],
            'ordered_skill_ids.*' => ['nullable', 'integer', 'min:1'],
        ]);
        $slotContext = (string) $data['slot_context'];

        try {
            $jobArtService->reorderSlots($character, $slotContext, $data['ordered_skill_ids']);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => collect($e->errors())->flatten()->first() ?: '戦技の並び順を保存できませんでした。',
                'errors' => $e->errors(),
            ], 422);
        }

        $selectedSlots = $jobArtService->selectedSlots(
            $character->fresh(),
            $jobArtService->availabilityContextForSlotContext($slotContext),
            $slotContext,
        );
        $character = $character->fresh();
        $stats = app(CharacterStatusService::class)->getFinalStats($character);
        $diagnosis = $loadoutDiagnosisService->diagnose(
            $character,
            $selectedSlots,
            $slotContext,
            max(0, (int) ($stats['max_mp'] ?? $character->mp_base ?? 0)),
            $jobArtService->contextSpPolicy($character, $slotContext),
            $jobArtService->maxSlots(),
            $jobArtService->maxCost(),
        );

        return response()->json([
            'message' => '戦技の並び順を保存しました。',
            'slot_context' => $slotContext,
            'diagnosis_html' => view('job-arts.partials.loadout-diagnosis', compact('diagnosis'))->render(),
            'selected_slot_by_skill' => $selectedSlots
                ->mapWithKeys(fn ($slot): array => [(int) $slot->skill_id => (int) $slot->slot_no])
                ->all(),
        ]);
    }

    private function decorateArtsForDisplay(
        iterable $arts,
        Character $character,
        int $maxSp,
        JobArtV2SpCostCalculator $spCostCalculator,
        JobArtV2BattleRules $battleRules,
        JobArtV2LoadoutPresenter $loadoutPresenter,
    ): void
    {
        $currentJobId = $character->current_job_id !== null
            ? (int) $character->current_job_id
            : null;

        foreach ($arts as $art) {
            $art->setAttribute('job_art_icon_path', $this->jobArtIconPath($art));
            $art->setAttribute('job_art_display_sp_cost', $spCostCalculator->forCharacter($character, $art, $maxSp));
            $art->setAttribute('job_art_display_activation_rate', $battleRules->activationRateFor(
                $art,
                $currentJobId,
                (string) $art->getAttribute('job_art_origin'),
            ));
            $art->setAttribute('job_art_v2_loadout_display', $loadoutPresenter->forArt($currentJobId, $art));
        }
    }

    /**
     * @param iterable<mixed> $skills
     * @return list<array{lineage_key:string,lineage_name:string,resource_name:string,icon_path:?string}>
     */
    private function activeLineagesForSkills(
        ?int $currentJobId,
        iterable $skills,
        JobArtV2ResourceCatalog $resourceCatalog,
        JobArtLineageCatalog $lineageCatalog,
    ): array {
        return collect($resourceCatalog->resourcesForSkills($currentJobId, $skills))
            ->map(function (array $resource) use ($lineageCatalog): array {
                $lineageKey = (string) $resource['lineage_key'];

                return [
                    'lineage_key' => $lineageKey,
                    'lineage_name' => $lineageCatalog->nameForKey($lineageKey) ?? $lineageKey,
                    'resource_name' => (string) $resource['resource_name'],
                    'icon_path' => $lineageCatalog->iconPathForKey($lineageKey),
                ];
            })
            ->values()
            ->all();
    }

    private function starterPresetHighlightActive(): bool
    {
        $until = trim((string) config('battle.job_art_v2.official_preset_highlight_until', ''));
        if ($until === '') {
            return false;
        }

        try {
            return now()->lessThanOrEqualTo(Carbon::parse($until, (string) config('app.timezone')));
        } catch (\Throwable) {
            return false;
        }
    }

    private function jobArtIconPath(Skill $art): ?string
    {
        $jobId = (int) $art->job_id;
        $learnRank = (int) $art->learn_rank;
        if ($jobId <= 0 || $learnRank <= 0) {
            return null;
        }

        $relativePath = sprintf('images/job_art/job_art_%03d_%02d.webp', $jobId, $learnRank);

        return is_file(public_path($relativePath)) ? $relativePath : null;
    }

    private function slotContextDescriptions(JobArtService $jobArtService, bool $jobArtV2UiEnabled): array
    {
        if (!$jobArtV2UiEnabled) {
            return $jobArtService->slotContextDescriptions();
        }

        $descriptions = [
            'normal' => '通常探索で使う戦技です。低Costや継戦向きの戦技が扱いやすいです。',
            'boss' => 'ボス戦で使う戦技です。高Cost、回復、防御、弱体の戦技も候補にしやすいです。',
        ];
        if ($jobArtService->pvpSetEnabled()) {
            $descriptions['pvp'] = 'プレイヤーPvP、チャンプ戦、闘技場NPC戦で使う戦技です。Gold・ドロップなどの報酬補正は発動しません。';
        }

        return $descriptions;
    }

    private function displayTerm(Character $character): string
    {
        $currentJobId = $character->current_job_id !== null
            ? (int) $character->current_job_id
            : null;

        return app(JobArtV2LoadoutPresenter::class)->enabledForCurrentJob($currentJobId)
            ? '戦技'
            : '奥義';
    }

    private function activationPolicyDescriptions(
        JobArtService $jobArtService,
        JobArtV2BattleRules $battleRules,
        ?int $currentJobId,
    ): array
    {
        $descriptions = $jobArtService->activationPolicyDescriptions();
        $threshold = $battleRules->conserveThresholdPercentForCurrentJob($currentJobId);
        $descriptions['conserve'] = "SPが{$threshold}%以上ある時だけ発動します";

        return $descriptions;
    }

    public function policy(
        Request $request,
        JobArtService $jobArtService,
        JobArtV2LoadoutDiagnosisService $loadoutDiagnosisService,
    )
    {
        $character = Auth::user()->currentCharacter();
        if (!$character) {
            return redirect()->route('character.select');
        }

        $data = $request->validate([
            'activation_policy' => ['required', 'string'],
            'slot_context' => ['nullable', 'string', Rule::in($jobArtService->slotContexts())],
            'filter' => ['nullable', 'string'],
        ]);

        try {
            if (isset($data['slot_context'])) {
                $jobArtService->saveContextSpPolicy(
                    $character,
                    (string) $data['slot_context'],
                    (string) $data['activation_policy'],
                );
            } else {
                $jobArtService->saveActivationPolicy($character, (string) $data['activation_policy']);
            }
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => collect($e->errors())->flatten()->first() ?: 'SP方針を保存できませんでした。',
                    'errors' => $e->errors(),
                ], 422);
            }

            return back()->withErrors($e->errors())->withInput();
        }

        if ($request->expectsJson()) {
            $diagnosisHtml = null;
            if (isset($data['slot_context'])) {
                $slotContext = (string) $data['slot_context'];
                $stats = app(CharacterStatusService::class)->getFinalStats($character);
                $diagnosis = $loadoutDiagnosisService->diagnose(
                    $character,
                    $jobArtService->selectedSlots(
                        $character,
                        $jobArtService->availabilityContextForSlotContext($slotContext),
                        $slotContext,
                    ),
                    $slotContext,
                    max(0, (int) ($stats['max_mp'] ?? $character->mp_base ?? 0)),
                    (string) $data['activation_policy'],
                    $jobArtService->maxSlots(),
                    $jobArtService->maxCost(),
                );
                $diagnosisHtml = view('job-arts.partials.loadout-diagnosis', compact('diagnosis'))->render();
            }

            return response()->json([
                'message' => isset($data['slot_context'])
                    ? 'SP方針を保存しました。'
                    : $this->displayTerm($character) . '発動方針を保存しました。',
                'slot_context' => $data['slot_context'] ?? null,
                'activation_policy' => (string) $data['activation_policy'],
                'diagnosis_html' => $diagnosisHtml,
            ]);
        }

        return redirect()
            ->route('job-arts.index', ['filter' => $data['filter'] ?? 'available'])
            ->with('message', isset($data['slot_context'])
                ? 'SP方針を保存しました。'
                : $this->displayTerm($character) . '発動方針を保存しました。');
    }
}
