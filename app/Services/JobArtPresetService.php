<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterJobArtSlot;
use App\Models\JobArtPreset;
use App\Models\Skill;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class JobArtPresetService
{
    private readonly JobArtV2SlotConditionCatalog $slotConditionCatalog;

    public function __construct(
        private readonly JobArtService $jobArtService,
        private readonly JobArtV2FeatureGate $featureGate,
        private readonly JobArtPresetLimitProvider $limitProvider,
        ?JobArtV2SlotConditionCatalog $slotConditionCatalog = null,
    ) {
        $this->slotConditionCatalog = $slotConditionCatalog ?? app(JobArtV2SlotConditionCatalog::class);
    }

    public function enabledFor(Character $character): bool
    {
        return $this->featureGate->usesPresetsForCurrentJob(
            $character->current_job_id !== null ? (int) $character->current_job_id : null
        );
    }

    public function limitFor(Character $character): int
    {
        return $this->limitProvider->limitFor($character);
    }

    public function presetsForDisplay(Character $character, string $targetContext = 'normal'): array
    {
        $this->ensureEnabled($character);
        $targetContext = $this->validateContext($targetContext);

        return $character->jobArtPresets()
            ->with('slots.skill')
            ->latest('updated_at')
            ->latest('id')
            ->get()
            ->map(fn (JobArtPreset $preset): array => $this->displayData($character, $preset, $targetContext))
            ->all();
    }

    public function createFromCurrentLoadout(Character $character, string $name, string $sourceContext): JobArtPreset
    {
        $this->ensureEnabled($character);
        $name = $this->validatedName($name);
        $sourceContext = $this->validateContext($sourceContext);

        return DB::transaction(function () use ($character, $name, $sourceContext): JobArtPreset {
            Character::query()->whereKey($character->getKey())->lockForUpdate()->firstOrFail();
            if ($character->jobArtPresets()->count() >= $this->limitFor($character)) {
                throw ValidationException::withMessages([
                    'preset' => 'マイプリセットは1キャラクターにつき' . $this->limitFor($character) . '件まで保存できます。',
                ]);
            }

            $preset = $character->jobArtPresets()->create([
                'name' => $name,
                'current_job_id' => (int) $character->current_job_id,
                'source_context' => $sourceContext,
            ]);

            $character->jobArtSlots()
                ->where('battle_context', $sourceContext)
                ->whereBetween('slot_no', [1, JobArtService::V2_MAX_SLOTS])
                ->orderBy('slot_no')
                ->get()
                ->each(function (CharacterJobArtSlot $slot) use ($preset): void {
                    $preset->slots()->create([
                        'slot_no' => (int) $slot->slot_no,
                        'skill_id' => (int) $slot->skill_id,
                        'activation_policy' => $this->jobArtService->normalizeActivationPolicy((string) $slot->activation_policy),
                        'condition_key' => $this->slotConditionCatalog->normalize(
                            (string) $slot->condition_key,
                        ),
                    ]);
                });

            return $preset->load('slots.skill');
        });
    }

    public function rename(Character $character, int $presetId, string $name): JobArtPreset
    {
        $this->ensureEnabled($character);
        $preset = $this->ownedPreset($character, $presetId);
        $preset->update(['name' => $this->validatedName($name)]);

        return $preset;
    }

    public function delete(Character $character, int $presetId): void
    {
        $this->ensureEnabled($character);
        $this->ownedPreset($character, $presetId)->delete();
    }

    public function apply(Character $character, int $presetId, string $targetContext): void
    {
        $this->ensureEnabled($character);
        $targetContext = $this->validateContext($targetContext);

        DB::transaction(function () use ($character, $presetId, $targetContext): void {
            $preset = $this->ownedPreset($character, $presetId, true);
            $this->ensureCurrentJobMatches($character, $preset);
            [$slots, $policies, $conditions] = $this->presetInputs($preset->slots);

            $this->jobArtService->saveSlots(
                $character,
                $slots,
                $targetContext,
                $this->jobArtService->availabilityContextForSlotContext($targetContext),
                $policies,
                $conditions,
            );
        });
    }

    private function displayData(Character $character, JobArtPreset $preset, string $targetContext): array
    {
        [$slots] = $this->presetInputs($preset->slots);
        $skills = $preset->slots->pluck('skill')->filter(fn ($skill): bool => $skill instanceof Skill);
        $status = $this->applicationStatus($character, $preset, $targetContext);
        $applicationStatuses = collect($this->jobArtService->slotContexts())
            ->mapWithKeys(fn (string $context): array => [
                $context => $this->applicationStatus($character, $preset, $context),
            ])
            ->all();

        return [
            'id' => (int) $preset->id,
            'name' => (string) $preset->name,
            'current_job_id' => (int) $preset->current_job_id,
            'source_context' => $preset->source_context,
            'slot_count' => count($slots),
            'cost' => $this->jobArtService->totalEffectiveCostFor($character, $skills),
            'can_apply' => $status['can_apply'],
            'unavailable_reason' => $status['reason'],
            'application_statuses' => $applicationStatuses,
            'updated_at' => $preset->updated_at,
        ];
    }

    private function applicationStatus(Character $character, JobArtPreset $preset, string $targetContext): array
    {
        try {
            $this->ensureCurrentJobMatches($character, $preset);
            [$slots] = $this->presetInputs($preset->slots);
            $this->jobArtService->validateSlotConfiguration($character, $slots, $targetContext);

            return ['can_apply' => true, 'reason' => null];
        } catch (ValidationException $exception) {
            return [
                'can_apply' => false,
                'reason' => collect($exception->errors())->flatten()->first() ?: '現在の条件では適用できません。',
            ];
        }
    }

    private function ownedPreset(Character $character, int $presetId, bool $lock = false): JobArtPreset
    {
        $query = $character->jobArtPresets()->with('slots.skill');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->findOrFail($presetId);
    }

    private function presetInputs(EloquentCollection $presetSlots): array
    {
        $slots = [];
        $policies = [];
        $conditions = [];
        foreach ($presetSlots->sortBy('slot_no') as $slot) {
            $slotNo = (int) $slot->slot_no;
            $slots[$slotNo] = (int) $slot->skill_id;
            $policies[$slotNo] = $this->jobArtService->normalizeActivationPolicy((string) $slot->activation_policy);
            $conditions[$slotNo] = $this->slotConditionCatalog->normalize(
                (string) $slot->condition_key,
            );
        }

        return [$slots, $policies, $conditions];
    }

    private function ensureCurrentJobMatches(Character $character, JobArtPreset $preset): void
    {
        if ((int) $character->current_job_id !== (int) $preset->current_job_id) {
            throw ValidationException::withMessages([
                'preset' => '保存した時と現在の職業が異なるため、このプリセットは適用できません。',
            ]);
        }
    }

    private function ensureEnabled(Character $character): void
    {
        abort_unless($this->enabledFor($character), 404);
    }

    private function validateContext(string $context): string
    {
        if (!in_array($context, $this->jobArtService->slotContexts(), true)) {
            throw ValidationException::withMessages(['slot_context' => '戦技セット種別が正しくありません。']);
        }

        return $context;
    }

    private function validatedName(string $name): string
    {
        $name = trim($name);
        Validator::make(
            ['name' => $name],
            ['name' => ['required', 'string', 'min:1', 'max:20']],
            ['name.required' => 'プリセット名を入力してください。', 'name.max' => 'プリセット名は20文字以内で入力してください。']
        )->validate();

        return $name;
    }
}
