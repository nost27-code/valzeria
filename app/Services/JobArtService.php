<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterJobArtSlot;
use App\Models\Skill;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class JobArtService
{
    public const MAX_SLOTS = 3;
    public const MAX_COST = 5;
    public const V2_MAX_SLOTS = 5;
    public const V2_MAX_COST = 9;
    public const ACTIVATION_POLICIES = ['aggressive', 'normal', 'conserve', 'boss_only'];
    public const SLOT_ACTIVATION_POLICIES = ['aggressive', 'normal', 'conserve'];
    public const SLOT_CONTEXTS = ['normal', 'boss'];
    public const PVP_SLOT_CONTEXT = 'pvp';

    private readonly JobArtV2SlotConditionCatalog $slotConditionCatalog;
    private readonly JobArtV2DeckRoleResolver $deckRoleResolver;

    public function __construct(
        ?JobArtV2SlotConditionCatalog $slotConditionCatalog = null,
        ?JobArtV2DeckRoleResolver $deckRoleResolver = null,
    ) {
        // Keep direct construction used by legacy tests and small CLI tools compatible.
        $this->slotConditionCatalog = $slotConditionCatalog ?? app(JobArtV2SlotConditionCatalog::class);
        $this->deckRoleResolver = $deckRoleResolver ?? app(JobArtV2DeckRoleResolver::class);
    }

    /** @return array<string, string> */
    public function slotConditionLabels(): array
    {
        return $this->slotConditionCatalog->labels();
    }

    public function pvpSetEnabled(): bool
    {
        return (bool) config('battle.job_art_v2.pvp_set', false);
    }

    public function loadoutV2Enabled(): bool
    {
        return (bool) config('battle.job_art_v2.loadout_v2', false);
    }

    public function maxSlots(): int
    {
        return $this->loadoutV2Enabled() ? self::V2_MAX_SLOTS : self::MAX_SLOTS;
    }

    public function maxCost(): int
    {
        return $this->loadoutV2Enabled() ? self::V2_MAX_COST : self::MAX_COST;
    }

    public function slotContexts(): array
    {
        return $this->pvpSetEnabled()
            ? [...self::SLOT_CONTEXTS, self::PVP_SLOT_CONTEXT]
            : self::SLOT_CONTEXTS;
    }

    public function slotContextLabels(): array
    {
        $labels = [
            'normal' => '通常戦セット',
            'boss' => 'ボス戦セット',
        ];

        if ($this->pvpSetEnabled()) {
            $labels[self::PVP_SLOT_CONTEXT] = 'PvPセット';
        }

        return $labels;
    }

    public function slotContextDescriptions(): array
    {
        $descriptions = [
            'normal' => '通常探索で使う奥義です。低Costや継戦向きの奥義が扱いやすいです。',
            'boss' => 'ボス戦で使う奥義です。高Cost、回復、防御、弱体の奥義も候補にしやすいです。',
        ];

        if ($this->pvpSetEnabled()) {
            $descriptions[self::PVP_SLOT_CONTEXT] = 'プレイヤーPvP、チャンプ戦、闘技場NPC戦で使う奥義です。';
        }

        return $descriptions;
    }

    public function activationPolicyLabels(): array
    {
        return [
            'aggressive' => '積極',
            'normal' => '通常',
            'conserve' => '温存',
        ];
    }

    public function activationPolicyDescriptions(): array
    {
        return [
            'aggressive' => 'SPが足りていれば発動します',
            'normal' => 'SPが30%以上ある時だけ発動します',
            'conserve' => 'SPが60%以上ある時だけ発動します',
        ];
    }

    /** @return array<string, string> */
    public function contextSpPolicies(Character $character): array
    {
        return collect($this->slotContexts())
            ->mapWithKeys(fn (string $context): array => [
                $context => $this->contextSpPolicy($character, $context),
            ])
            ->all();
    }

    public function contextSpPolicy(Character $character, string $slotContext): string
    {
        $slotContext = $this->normalizeSlotContext($slotContext);
        if (! Schema::hasTable('character_job_art_context_settings')) {
            return 'aggressive';
        }

        $policy = $character->jobArtContextSettings()
            ->where('battle_context', $slotContext)
            ->value('sp_policy');

        return $policy === null
            ? 'aggressive'
            : $this->normalizeActivationPolicy((string) $policy);
    }

    public function saveContextSpPolicy(Character $character, string $slotContext, string $policy): void
    {
        $slotContext = $this->normalizeSlotContext($slotContext);
        $policy = $this->normalizeActivationPolicyStrict($policy);
        if (! Schema::hasTable('character_job_art_context_settings')) {
            throw ValidationException::withMessages([
                'sp_policy' => 'SP方針を保存する準備が完了していません。',
            ]);
        }

        $character->jobArtContextSettings()->updateOrCreate(
            ['battle_context' => $slotContext],
            ['sp_policy' => $policy],
        );
    }

    public function availableArts(Character $character, string $context = 'pve'): Collection
    {
        $character->loadMissing(['jobHistories.jobClass', 'currentJob']);
        $histories = $character->jobHistories->keyBy('job_class_id');
        $currentJobId = (int) $character->current_job_id;
        $currentHistory = $histories->get($currentJobId);
        $currentRank = (int) ($currentHistory?->job_level ?? 1);

        return Skill::query()
            ->where('skill_type', 'job_art')
            ->with('jobClass')
            ->orderBy('job_id')
            ->orderBy('sort_order')
            ->get()
            ->filter(function (Skill $skill) use ($character, $histories, $currentJobId, $currentRank, $context) {
                return $this->availabilityFor($skill, $character, $histories, $currentJobId, $currentRank, $context)['available'];
            })
            ->map(function (Skill $skill) use ($character, $histories, $currentJobId, $currentRank, $context) {
                $availability = $this->availabilityFor($skill, $character, $histories, $currentJobId, $currentRank, $context);
                $skill->setAttribute('job_art_origin', $availability['origin']);
                // 戦技v2では、習得済みの戦技は現在職との関係にかかわらず
                // カードに書かれた効果を100%発揮する。
                $skill->setAttribute('job_art_rate', 1.0);
                $skill->setAttribute('job_art_effective_cost', $this->effectiveArtCostFor($character, $skill));
                return $skill;
            })
            ->values();
    }

    public function selectedSlots(
        Character $character,
        string $context = 'pve',
        string $slotContext = 'normal',
        ?Collection $availableArts = null,
    ): Collection
    {
        $slotContext = $this->normalizeSlotContext($slotContext);
        $available = ($availableArts ?? $this->availableArts($character, $context))->keyBy('id');
        $slots = $character->jobArtSlots()
            ->with('skill.jobClass')
            ->where('battle_context', $slotContext)
            ->orderBy('slot_no')
            ->get()
            ->map(function (CharacterJobArtSlot $slot) use ($available): ?CharacterJobArtSlot {
                $skill = $available->get($slot->skill_id);
                if (!$skill) {
                    return null;
                }

                $slot->setRelation('skill', $skill);
                $slot->setAttribute('job_art_slot_condition', $this->slotConditionCatalog->normalize(
                    $this->hasConditionKeyColumn() ? (string) $slot->condition_key : null,
                ));
                return $slot;
            })
            ->filter()
            ->values();

        return $this->evaluateLoadoutSlots($character, $slots)
            ->filter(fn (CharacterJobArtSlot $slot): bool => (int) $slot->slot_no <= $this->maxSlots())
            ->values();
    }

    public function battleArtsFor(Character $character, string $context = 'pve'): Collection
    {
        $available = $this->availableArts($character, $context)->keyBy('id');
        $slotContext = $this->battleSlotContext($context);
        $usesV2Loadout = $this->usesV2LoadoutFor($character);
        $contextSpPolicy = $usesV2Loadout
            ? $this->contextSpPolicy($character, $slotContext)
            : null;

        $slots = $character->jobArtSlots()
            ->with('skill.jobClass')
            ->where('battle_context', $slotContext)
            ->orderBy('slot_no')
            ->get()
            ->map(function (CharacterJobArtSlot $slot) use ($available): ?CharacterJobArtSlot {
                $skill = $available->get($slot->skill_id);
                if (!$skill) {
                    return null;
                }

                $slot->setRelation('skill', $skill);
                $slot->setAttribute('job_art_slot_condition', $this->slotConditionCatalog->normalize(
                    $this->hasConditionKeyColumn() ? (string) $slot->condition_key : null,
                ));
                return $slot;
            })
            ->filter()
            ->values();

        return $this->evaluateLoadoutSlots($character, $slots)
            ->filter(fn (CharacterJobArtSlot $slot): bool => (bool) $slot->getAttribute('job_art_active'))
            ->map(function (CharacterJobArtSlot $slot) use ($usesV2Loadout, $contextSpPolicy): Skill {
                $skill = $slot->skill;

                $skill->setAttribute('slot_no', (int) $slot->slot_no);
                $skill->setAttribute('job_art_effective_cost', (int) $slot->getAttribute('job_art_effective_cost'));
                // 戦技v2は個別方針ではなく、通常・ボス・PvPごとのSP方針を
                // 5枠すべてへ適用する。旧戦技は保存済みの個別方針を維持する。
                $skill->setAttribute(
                    'job_art_activation_policy',
                    $usesV2Loadout
                        ? $contextSpPolicy
                        : $this->normalizeActivationPolicy((string) $slot->activation_policy),
                );
                $skill->setAttribute('job_art_slot_condition', $this->slotConditionCatalog->normalize(
                    $usesV2Loadout
                        ? JobArtV2SlotConditionCatalog::ALWAYS
                        : (string) $slot->getAttribute('job_art_slot_condition'),
                ));
                return $skill;
            })
            ->values();
    }

    /**
     * @param array<int, int|null|string> $orderedSkillIds
     */
    public function reorderSlots(Character $character, string $slotContext, array $orderedSkillIds): void
    {
        $slotContext = $this->normalizeSlotContext($slotContext);
        if (! $this->usesV2LoadoutFor($character)) {
            throw ValidationException::withMessages([
                'slots' => 'この戦技セットでは並び替えを利用できません。',
            ]);
        }

        $orderedSkillIds = array_values($orderedSkillIds);
        if (count($orderedSkillIds) !== $this->maxSlots()) {
            throw ValidationException::withMessages([
                'slots' => '並び替え対象の枠数が正しくありません。',
            ]);
        }

        $normalizedOrder = array_map(
            static function (mixed $skillId): ?int {
                if ($skillId === null || $skillId === '') {
                    return null;
                }

                $skillId = (int) $skillId;
                return $skillId > 0 ? $skillId : null;
            },
            $orderedSkillIds,
        );
        $submittedSkillIds = array_values(array_filter($normalizedOrder, static fn (?int $skillId): bool => $skillId !== null));
        if (count($submittedSkillIds) !== count(array_unique($submittedSkillIds))) {
            throw ValidationException::withMessages([
                'slots' => '同じ戦技を複数の枠へ並べることはできません。',
            ]);
        }

        $rows = $character->jobArtSlots()
            ->where('battle_context', $slotContext)
            ->where('slot_no', '<=', $this->maxSlots())
            ->orderBy('slot_no')
            ->get();
        $currentSkillIds = $rows->pluck('skill_id')->map(static fn ($skillId): int => (int) $skillId)->all();
        $expectedSkillIds = $currentSkillIds;
        sort($expectedSkillIds);
        $actualSkillIds = $submittedSkillIds;
        sort($actualSkillIds);
        if ($expectedSkillIds !== $actualSkillIds) {
            throw ValidationException::withMessages([
                'slots' => '現在の戦技セットと並び替え内容が一致しません。画面を再読み込みしてください。',
            ]);
        }

        $rowsBySkillId = $rows->keyBy(static fn (CharacterJobArtSlot $slot): int => (int) $slot->skill_id);
        DB::transaction(function () use ($rows, $rowsBySkillId, $normalizedOrder): void {
            // slot_no の一意制約へ触れないよう、一度だけ安全な退避番号へ移す。
            foreach ($rows->values() as $index => $slot) {
                $slot->forceFill(['slot_no' => 101 + $index])->save();
            }

            foreach ($normalizedOrder as $index => $skillId) {
                if ($skillId === null) {
                    continue;
                }

                $rowsBySkillId->get($skillId)?->forceFill(['slot_no' => $index + 1])->save();
            }
        });
    }

    public function saveSlots(
        Character $character,
        array $slotSkillIds,
        string $slotContext = 'normal',
        string $availabilityContext = 'pve',
        array $slotPolicies = [],
        ?array $slotConditions = null,
    ): void
    {
        $slotContext = $this->normalizeSlotContext($slotContext);
        $this->validateSubmittedSlotNumbers($slotSkillIds);
        $normalized = $this->normalizeSlotInput($slotSkillIds);
        $this->validateSlots($character, $normalized, $availabilityContext);
        $preservedSkillIds = $character->jobArtSlots()
            ->where('battle_context', $slotContext)
            ->where('slot_no', '>', $this->maxSlots())
            ->pluck('skill_id')
            ->map(fn ($skillId): int => (int) $skillId)
            ->all();
        if (array_intersect(array_values($normalized), $preservedSkillIds) !== []) {
            throw ValidationException::withMessages([
                'slots' => '休止中の後方枠に同じ奥義が保存されています。枠数を戻してから変更してください。',
            ]);
        }
        $policies = $this->normalizeSlotPolicies($slotPolicies, $normalized);
        $conditions = $this->normalizeSlotConditions($slotConditions ?? [], $normalized);

        DB::transaction(function () use ($character, $normalized, $slotContext, $policies, $conditions) {
            $character->jobArtSlots()
                ->where('battle_context', $slotContext)
                ->where('slot_no', '<=', $this->maxSlots())
                ->delete();
            foreach ($normalized as $slotNo => $skillId) {
                $payload = [
                    'character_id' => $character->id,
                    'battle_context' => $slotContext,
                    'slot_no' => $slotNo,
                    'skill_id' => $skillId,
                ];

                if ($this->hasActivationPolicyColumn()) {
                    $payload['activation_policy'] = $policies[$slotNo] ?? 'normal';
                }
                if ($this->hasConditionKeyColumn()) {
                    $payload['condition_key'] = $conditions[$slotNo] ?? JobArtV2SlotConditionCatalog::ALWAYS;
                }

                CharacterJobArtSlot::create($payload);
            }
        });
    }

    public function validateSlotConfiguration(Character $character, array $slotSkillIds, string $slotContext): void
    {
        $this->validateSlotConfigurationAgainstAvailableArts($character, $slotSkillIds, $slotContext);
    }

    public function validateSlotConfigurationAgainstAvailableArts(
        Character $character,
        array $slotSkillIds,
        string $slotContext,
        ?Collection $availableArts = null,
    ): void
    {
        $slotContext = $this->normalizeSlotContext($slotContext);
        $this->validateSubmittedSlotNumbers($slotSkillIds);
        $this->validateSlots(
            $character,
            $this->normalizeSlotInput($slotSkillIds),
            $this->availabilityContextForSlotContext($slotContext),
            $availableArts,
        );
    }

    public function assignToSlot(Character $character, int $skillId, ?int $slotNo, string $slotContext = 'normal'): void
    {
        $slotContext = $this->normalizeSlotContext($slotContext);
        $availabilityContext = $this->availabilityContextForSlotContext($slotContext);
        $selectedSlots = $this->selectedSlots($character, $availabilityContext, $slotContext);
        $slots = $selectedSlots
            ->mapWithKeys(fn (CharacterJobArtSlot $slot): array => [(int) $slot->slot_no => (int) $slot->skill_id])
            ->all();
        $policies = $selectedSlots
            ->mapWithKeys(fn (CharacterJobArtSlot $slot): array => [(int) $slot->slot_no => $this->normalizeActivationPolicy((string) $slot->activation_policy)])
            ->all();
        $conditions = $selectedSlots
            ->mapWithKeys(fn (CharacterJobArtSlot $slot): array => [(int) $slot->slot_no => $this->slotConditionCatalog->normalize((string) $slot->getAttribute('job_art_slot_condition'))])
            ->all();
        $movedPolicy = 'normal';
        $movedCondition = JobArtV2SlotConditionCatalog::ALWAYS;

        foreach ($slots as $existingSlotNo => $existingSkillId) {
            if ($existingSkillId === $skillId || ($slotNo !== null && $existingSlotNo === $slotNo)) {
                if ($existingSkillId === $skillId) {
                    $movedPolicy = $policies[$existingSlotNo] ?? 'normal';
                    $movedCondition = $conditions[$existingSlotNo] ?? JobArtV2SlotConditionCatalog::ALWAYS;
                }
                unset($slots[$existingSlotNo]);
                unset($policies[$existingSlotNo]);
                unset($conditions[$existingSlotNo]);
            }
        }

        if ($slotNo !== null) {
            $slots[$slotNo] = $skillId;
            $policies[$slotNo] = $movedPolicy;
            $conditions[$slotNo] = $movedCondition;
        }

        ksort($slots);
        $this->saveSlots($character, $slots, $slotContext, $availabilityContext, $policies, $conditions);
    }

    public function setSlot(
        Character $character,
        string $slotContext,
        int $slotNo,
        ?int $skillId,
        ?string $policy = null,
        ?string $condition = null,
    ): void
    {
        $slotContext = $this->normalizeSlotContext($slotContext);
        $availabilityContext = $this->availabilityContextForSlotContext($slotContext);
        $selectedSlots = $this->selectedSlots($character, $availabilityContext, $slotContext);
        $slots = $selectedSlots
            ->mapWithKeys(fn (CharacterJobArtSlot $slot): array => [(int) $slot->slot_no => (int) $slot->skill_id])
            ->all();
        $policies = $selectedSlots
            ->mapWithKeys(fn (CharacterJobArtSlot $slot): array => [(int) $slot->slot_no => $this->normalizeActivationPolicy((string) $slot->activation_policy)])
            ->all();
        $conditions = $selectedSlots
            ->mapWithKeys(fn (CharacterJobArtSlot $slot): array => [(int) $slot->slot_no => $this->slotConditionCatalog->normalize((string) $slot->getAttribute('job_art_slot_condition'))])
            ->all();
        $preservedCondition = $conditions[$slotNo] ?? JobArtV2SlotConditionCatalog::ALWAYS;

        foreach ($slots as $existingSlotNo => $existingSkillId) {
            if ($existingSlotNo === $slotNo || ($skillId !== null && $existingSkillId === $skillId)) {
                unset($slots[$existingSlotNo]);
                unset($policies[$existingSlotNo]);
                if ($skillId !== null && $existingSkillId === $skillId) {
                    $preservedCondition = $conditions[$existingSlotNo] ?? $preservedCondition;
                }
                unset($conditions[$existingSlotNo]);
            }
        }

        if ($skillId !== null) {
            $slots[$slotNo] = $skillId;
            $policies[$slotNo] = $this->normalizeActivationPolicy((string) ($policy ?? 'normal'));
            $conditions[$slotNo] = $this->slotConditionCatalog->normalize($condition ?? $preservedCondition);
        }

        ksort($slots);
        $this->saveSlots($character, $slots, $slotContext, $availabilityContext, $policies, $conditions);
    }

    public function saveActivationPolicy(Character $character, string $policy): void
    {
        if (!in_array($policy, self::ACTIVATION_POLICIES, true)) {
            throw ValidationException::withMessages(['activation_policy' => '奥義発動方針が正しくありません。']);
        }

        $character->forceFill(['job_art_activation_policy' => $policy])->save();
    }

    public function totalCost(Collection $skills): int
    {
        return (int) $skills->sum(
            fn (Skill $skill): int => (int) ($skill->getAttribute('job_art_effective_cost') ?? $skill->art_cost)
        );
    }

    public function totalEffectiveCostFor(Character $character, Collection $skills): int
    {
        $resolution = $this->deckRoleResolver->resolveSkills(
            $character->current_job_id !== null ? (int) $character->current_job_id : null,
            $skills,
        );

        return (int) $skills->sum(
            fn (Skill $skill): int => $this->effectiveArtCostForResolution($character, $skill, $resolution)
        );
    }

    public function effectiveArtCostFor(Character $character, Skill $skill): int
    {
        $masterCost = max(0, (int) $skill->art_cost);
        if (! $this->usesV2LoadoutFor($character) || ! $skill->isJobArt()) {
            return $masterCost;
        }

        // v2のCostは現在職・継承・主副・出張で変えず、戦技の段階だけで決める。
        // DBのart_costはflag OFF時のlegacy rollback用として保持する。
        return match ((int) $skill->learn_rank) {
            1 => 1,
            5 => 2,
            9 => 3,
            default => $masterCost,
        };
    }

    public function evaluateLoadoutSlots(Character $character, Collection $slots): Collection
    {
        $cumulativeCost = 0;
        $costLimitReached = false;
        $roleResolution = $this->deckRoleResolver->resolveSkills(
            $character->current_job_id !== null ? (int) $character->current_job_id : null,
            $slots
                ->filter(fn (CharacterJobArtSlot $slot): bool => (int) $slot->slot_no <= $this->maxSlots())
                ->pluck('skill')
                ->filter(fn ($skill): bool => $skill instanceof Skill),
        );

        return $slots
            ->sortBy(fn (CharacterJobArtSlot $slot): int => (int) $slot->slot_no)
            ->map(function (CharacterJobArtSlot $slot) use ($character, $roleResolution, &$cumulativeCost, &$costLimitReached): CharacterJobArtSlot {
                $slotNo = (int) $slot->slot_no;
                $effectiveCost = $slot->skill instanceof Skill
                    ? $this->effectiveArtCostForResolution($character, $slot->skill, $roleResolution)
                    : 0;
                $inactiveReason = null;

                if ($slotNo > $this->maxSlots()) {
                    $inactiveReason = 'slot_limit';
                } elseif ($this->loadoutV2Enabled()) {
                    if ($costLimitReached || $cumulativeCost + $effectiveCost > $this->maxCost()) {
                        $inactiveReason = 'cost_limit';
                        $costLimitReached = true;
                    } else {
                        $cumulativeCost += $effectiveCost;
                    }
                } else {
                    // The legacy battle path did not pause already-saved rows by Cost.
                    $cumulativeCost += $effectiveCost;
                }

                $slot->setAttribute('job_art_effective_cost', $effectiveCost);
                $slot->setAttribute('job_art_inactive_reason', $inactiveReason);
                $slot->setAttribute('job_art_active', $inactiveReason === null);
                if ($slot->skill instanceof Skill) {
                    $slot->skill->setAttribute('job_art_effective_cost', $effectiveCost);
                }

                return $slot;
            })
            ->values();
    }

    public function setupSeenSessionKey(Character $character): string
    {
        return 'job_art_setup_seen_' . (int) $character->id;
    }

    public function setupSignature(Character $character, ?Collection $availableArts = null, ?Collection $selectedSlots = null): string
    {
        $usesV2Loadout = $this->usesV2LoadoutFor($character);
        $availableArts ??= $this->availableArts($character, 'pve');
        $selectedSlots ??= collect($this->slotContexts())
            ->flatMap(fn (string $slotContext): Collection => $this->selectedSlots(
                $character,
                $this->availabilityContextForSlotContext($slotContext),
                $slotContext
            ))
            ->values();
        $selectedSkills = $selectedSlots->pluck('skill')->filter()->values();

        return sha1(json_encode([
            'available' => $availableArts->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
            'selected' => $selectedSlots
                ->map(fn (CharacterJobArtSlot $slot): array => [
                    'context' => (string) ($slot->battle_context ?: 'normal'),
                    'slot' => (int) $slot->slot_no,
                    'skill' => (int) $slot->skill_id,
                    'policy' => $usesV2Loadout
                        ? $this->contextSpPolicy($character, (string) ($slot->battle_context ?: 'normal'))
                        : $this->normalizeActivationPolicy((string) $slot->activation_policy),
                    'condition' => $usesV2Loadout
                        ? JobArtV2SlotConditionCatalog::ALWAYS
                        : $this->slotConditionCatalog->normalize(
                            $this->hasConditionKeyColumn() ? (string) $slot->condition_key : null,
                        ),
                ])
                ->sortBy(fn (array $slot): string => $slot['context'] . ':' . $slot['slot'])
                ->values()
                ->all(),
            'selected_count' => $selectedSkills->count(),
            'total_cost' => $this->totalCost($selectedSkills),
            'context_sp_policies' => $usesV2Loadout ? $this->contextSpPolicies($character) : [],
        ], JSON_THROW_ON_ERROR));
    }

    public function contextAllows(Skill $skill, string $context): bool
    {
        return match ($context) {
            'boss' => (bool) $skill->boss_enabled,
            'champ' => (bool) $skill->champ_enabled && !$skill->isRewardArt(),
            default => (bool) $skill->pve_enabled,
        };
    }

    private function validateSlots(
        Character $character,
        array $slotSkillIds,
        string $availabilityContext = 'pve',
        ?Collection $availableArts = null,
    ): void
    {
        $usesV2Loadout = $this->usesV2LoadoutFor($character);
        if (count($slotSkillIds) > $this->maxSlots()) {
            throw ValidationException::withMessages(['slots' => '戦技は最大' . $this->maxSlots() . 'つまで設定できます。']);
        }

        $available = ($availableArts ?? $this->availableArts($character, $availabilityContext))->keyBy('id');
        $selected = collect();
        $seen = [];

        foreach ($slotSkillIds as $slotNo => $skillId) {
            if ($slotNo < 1 || $slotNo > $this->maxSlots()) {
                throw ValidationException::withMessages(['slots' => '戦技枠は1〜' . $this->maxSlots() . 'のみ使用できます。']);
            }
            if (isset($seen[$skillId])) {
                throw ValidationException::withMessages(['slots' => '同じ戦技を複数セットすることはできません。']);
            }
            $seen[$skillId] = true;

            $skill = $available->get($skillId);
            if (!$skill) {
                throw ValidationException::withMessages(['slots' => 'この戦技はまだ習得していません。']);
            }
            if (! $usesV2Loadout
                && $skill->isTimeLimited()
                && $skill->getAttribute('job_art_origin') !== 'current'
            ) {
                throw ValidationException::withMessages(['slots' => '時空系の奥義は時空王でのみ使用できます。']);
            }

            $selected->push($skill);
        }

        if ($usesV2Loadout && $selected->where('learn_rank', 9)->count() > 1) {
            throw ValidationException::withMessages(['slots' => '奥義は1セットにつき1つまで設定できます。']);
        }

        if ($this->totalEffectiveCostFor($character, $selected) > $this->maxCost()) {
            throw ValidationException::withMessages(['slots' => '戦技Costの合計は' . $this->maxCost() . 'までです。']);
        }

        if (! $usesV2Loadout) {
            foreach (['HEAL' => '回復系の奥義は1つまでしか設定できません。', 'REWARD' => '報酬系の奥義は1つまでしか設定できません。', 'TIME' => '時空系の奥義は時空王でのみ使用できます。', 'GUTS' => '踏みとどまり系の奥義は1つまでしか設定できません。'] as $group => $message) {
                if ($selected->where('limit_group', $group)->count() > 1) {
                    throw ValidationException::withMessages(['slots' => $message]);
                }
            }
        }
    }

    private function effectiveArtCostForResolution(
        Character $character,
        Skill $skill,
        JobArtV2DeckRoleResolution $resolution,
    ): int {
        return $this->effectiveArtCostFor($character, $skill);
    }

    private function normalizeSlotInput(array $slotSkillIds): array
    {
        $normalized = [];
        foreach ($slotSkillIds as $slotNo => $skillId) {
            $slotNo = (int) $slotNo;
            $skillId = (int) $skillId;
            if ($slotNo < 1 || $slotNo > $this->maxSlots() || $skillId <= 0) {
                continue;
            }
            $normalized[$slotNo] = $skillId;
        }

        ksort($normalized);
        return $normalized;
    }

    private function validateSubmittedSlotNumbers(array $slotSkillIds): void
    {
        foreach ($slotSkillIds as $slotNo => $skillId) {
            if ((int) $skillId <= 0) {
                continue;
            }

            $slotNo = (int) $slotNo;
            if ($slotNo < 1 || $slotNo > $this->maxSlots()) {
                throw ValidationException::withMessages([
                    'slots' => '奥義枠は1〜' . $this->maxSlots() . 'のみ使用できます。',
                ]);
            }
        }
    }

    private function normalizeSlotPolicies(array $slotPolicies, array $normalizedSlots): array
    {
        $policies = [];
        foreach ($normalizedSlots as $slotNo => $skillId) {
            $policies[(int) $slotNo] = $this->normalizeActivationPolicy((string) ($slotPolicies[$slotNo] ?? 'normal'));
        }

        return $policies;
    }

    private function normalizeSlotConditions(array $slotConditions, array $normalizedSlots): array
    {
        $conditions = [];
        foreach ($normalizedSlots as $slotNo => $skillId) {
            $conditions[(int) $slotNo] = $this->slotConditionCatalog->normalize(
                $slotConditions[$slotNo] ?? JobArtV2SlotConditionCatalog::ALWAYS,
            );
        }

        return $conditions;
    }

    public function battleSlotContext(string $battleContext): string
    {
        if (in_array($battleContext, ['champ', 'pvp', 'arena_npc'], true)) {
            return $this->pvpSetEnabled() ? self::PVP_SLOT_CONTEXT : 'boss';
        }

        return $battleContext === 'boss' ? 'boss' : 'normal';
    }

    public function availabilityContextForSlotContext(string $slotContext): string
    {
        return match ($slotContext) {
            'boss' => 'boss',
            self::PVP_SLOT_CONTEXT => 'champ',
            default => 'pve',
        };
    }

    private function normalizeSlotContext(string $slotContext): string
    {
        if (!in_array($slotContext, $this->slotContexts(), true)) {
            throw ValidationException::withMessages(['slot_context' => '奥義セット種別が正しくありません。']);
        }

        return $slotContext;
    }

    public function normalizeActivationPolicy(string $policy): string
    {
        return in_array($policy, self::SLOT_ACTIVATION_POLICIES, true) ? $policy : 'normal';
    }

    public function normalizeActivationPolicyStrict(string $policy): string
    {
        if (! in_array($policy, self::SLOT_ACTIVATION_POLICIES, true)) {
            throw ValidationException::withMessages(['sp_policy' => 'SP方針が正しくありません。']);
        }

        return $policy;
    }

    private function usesV2LoadoutFor(Character $character): bool
    {
        $currentJobId = $character->current_job_id !== null
            ? (int) $character->current_job_id
            : null;

        return app(JobArtV2FeatureGate::class)
            ->usesLoadoutUiForCurrentJob($currentJobId);
    }

    private function hasActivationPolicyColumn(): bool
    {
        return Schema::hasColumn('character_job_art_slots', 'activation_policy');
    }

    private function hasConditionKeyColumn(): bool
    {
        return Schema::hasColumn('character_job_art_slots', 'condition_key');
    }

    private function availabilityFor(Skill $skill, Character $character, Collection $histories, int $currentJobId, int $currentRank, string $context): array
    {
        if (!$this->contextAllows($skill, $context)) {
            return ['available' => false, 'origin' => 'disabled', 'rate' => 0.0];
        }

        if ((int) $skill->job_id === $currentJobId) {
            return [
                'available' => $currentRank >= (int) $skill->learn_rank,
                'origin' => 'current',
                'rate' => 1.0,
            ];
        }

        $history = $histories->get((int) $skill->job_id);
        $maxRank = (int) ($history?->jobClass?->max_job_level ?? 10);
        $mastered = (bool) ($history?->is_mastered ?? false) || (int) ($history?->job_level ?? 0) >= $maxRank;
        if (!$mastered) {
            return ['available' => false, 'origin' => 'locked', 'rate' => 0.0];
        }
        if (!$skill->inherit_on_master || $skill->isTimeLimited()) {
            return ['available' => false, 'origin' => 'not_inheritable', 'rate' => 0.0];
        }

        return [
            'available' => true,
            'origin' => 'inherited',
            'rate' => (float) ($skill->inherited_rate ?: 1.0),
        ];
    }
}
