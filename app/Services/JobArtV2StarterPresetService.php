<?php

namespace App\Services;

use App\Models\Character;
use App\Models\Skill;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class JobArtV2StarterPresetService
{
    public const FINISHER = 'finisher';
    public const CYCLE = 'cycle';
    public const TACTICAL = 'tactical';

    /** @var array<string, Collection<int, Skill>> */
    private array $availableArtsCache = [];

    /** @var Collection<string, Skill>|null */
    private ?Collection $masterArtsCache = null;

    public function __construct(
        private readonly JobArtService $jobArtService,
        private readonly JobArtV2FeatureGate $featureGate,
        private readonly JobArtV2PrototypeCatalog $prototypeCatalog,
        private readonly JobArtV2ResourceCatalog $resourceCatalog,
        private readonly JobArtV2LoadoutPresenter $loadoutPresenter,
        private readonly JobArtLineageCatalog $lineageCatalog,
        private readonly JobArtV2OfficialPresetCatalog $officialPresetCatalog,
        private readonly JobArtV2SlotConditionCatalog $slotConditionCatalog,
    ) {}

    public function enabledFor(Character $character): bool
    {
        $currentJobId = $character->current_job_id !== null
            ? (int) $character->current_job_id
            : null;

        return $this->featureGate->usesCDesignPrototypeForCurrentJob($currentJobId);
    }

    /** @return array<int, array<string, mixed>> */
    public function presetsForDisplay(Character $character, string $slotContext): array
    {
        if (! $this->enabledFor($character) || ! $this->validContext($slotContext)) {
            return [];
        }

        $presets = [];
        foreach ($this->officialPresetCatalog->lineages() as $lineage) {
            foreach ($this->officialPresetCatalog->styles() as $style) {
                $presets[] = $this->displayPreset($character, $lineage, $style, $slotContext);
            }
        }

        return $presets;
    }

    public function presetCountForDisplay(Character $character): int
    {
        if (! $this->enabledFor($character)) {
            return 0;
        }

        return count($this->officialPresetCatalog->lineages())
            * count($this->officialPresetCatalog->styles());
    }

    /** @return array<string, mixed>|null */
    public function resourceGuideForDisplay(Character $character): ?array
    {
        if (! $this->enabledFor($character)) {
            return null;
        }

        $metadata = $this->prototypeCatalog->jobResourceMetadata((int) $character->current_job_id);
        if ($metadata === null) {
            return null;
        }

        $gainDefinitions = [
            'normal_attack_hit_gain_points' => '通常攻撃HIT',
            'normal_attack_miss_gain_points' => '通常攻撃MISS',
            'self_damage_gain_points' => '実自傷成立',
            'direct_attack_damage_received_gain_points' => '攻撃本体で1以上のダメージを受ける',
            'parry_success_gain_points' => '受け流し成功（さらに）',
            'damage_mitigated_gain_points' => '実際に1以上軽減',
            'cleanse_success_gain_points' => '浄化成功',
            'non_job_art_action_gain_points' => '通常攻撃／現在職技の手番',
        ];
        $gains = [];
        foreach ($gainDefinitions as $key => $label) {
            $points = max(0, (int) ($metadata[$key] ?? 0));
            if ($points > 0) {
                $gains[] = ['label' => $label, 'points' => $points];
            }
        }

        return [
            'lineage_name' => (string) $metadata['lineage_name'],
            'resource_name' => (string) $metadata['resource_name'],
            'max_points' => (int) $metadata['resource_max_points'],
            'gains' => $gains,
        ];
    }

    public function apply(
        Character $character,
        string $lineage,
        string $style,
        string $slotContext,
        ?string $variant = null,
    ): void {
        if (! $this->enabledFor($character)
            || ! $this->validLineage($lineage)
            || ! $this->validStyle($style)
            || ! $this->validContext($slotContext)
        ) {
            throw ValidationException::withMessages([
                'starter_preset' => 'この公式プリセットは使用できません。',
            ]);
        }

        $display = $this->displayPreset($character, $lineage, $style, $slotContext);
        $variant ??= $display['current_variant']['key'] ?? null;
        if (! is_string($variant)) {
            throw ValidationException::withMessages([
                'starter_preset' => (string) ($display['unavailable_reason']
                    ?? 'この公式プリセットに必要な戦技をまだ習得していません。'),
            ]);
        }

        $configuration = $this->variantConfiguration($character, $lineage, $style, $variant, $slotContext);
        if (! $configuration['can_apply']) {
            throw ValidationException::withMessages([
                'starter_preset' => (string) ($configuration['unavailable_reason']
                    ?? 'この構成に必要な5つの戦技をすべて習得してください。'),
            ]);
        }

        $policies = array_fill_keys(array_keys($configuration['slots']), 'normal');
        $this->jobArtService->saveSlots(
            $character,
            $configuration['slots'],
            $slotContext,
            $this->jobArtService->availabilityContextForSlotContext($slotContext),
            $policies,
            $configuration['conditions'],
        );
    }

    /** @return array<string, mixed> */
    private function displayPreset(Character $character, string $lineage, string $style, string $slotContext): array
    {
        $preset = $this->officialPresetCatalog->preset($lineage, $style) ?? [];
        $lineageName = (string) ($this->lineageCatalog->nameForKey($lineage) ?? '');
        $resourceName = $this->resourceNameForPreset($lineage, $style);
        $variants = collect($this->officialPresetCatalog->variants())
            ->mapWithKeys(fn (string $variant): array => [
                $variant => $this->variantConfiguration($character, $lineage, $style, $variant, $slotContext),
            ]);
        $current = collect($this->officialPresetCatalog->variants())
            ->reverse()
            ->map(fn (string $variant) => $variants->get($variant))
            ->first(fn (?array $configuration): bool => (bool) ($configuration['can_apply'] ?? false));
        $completion = $variants->get(JobArtV2OfficialPresetCatalog::CROWN);
        $next = $this->nextVariant($variants, $current['key'] ?? null);
        $target = $current ?? $variants->get(JobArtV2OfficialPresetCatalog::ADVANCED);
        $status = match (true) {
            ($current['key'] ?? null) === JobArtV2OfficialPresetCatalog::CROWN => 'COMPLETE',
            $current !== null => 'LOWER_VARIANT_AVAILABLE',
            default => 'LOCKED',
        };
        $reason = null;
        if ($current === null) {
            $missing = (int) ($target['missing_count'] ?? $this->jobArtService->maxSlots());
            $reason = $missing > 0
                ? "現在使える完成版がありません。上級版まであと{$missing}戦技です。"
                : (string) ($target['unavailable_reason'] ?? '現在の構成条件では適用できません。');
        }

        return [
            'key' => "{$lineage}:{$style}",
            'lineage_key' => $lineage,
            'style_key' => $style,
            'name' => (string) ($preset['name'] ?? '公式型'),
            'build_name' => (string) ($preset['build_name'] ?? ''),
            'purpose' => (string) ($preset['purpose'] ?? ''),
            'description' => (string) ($preset['description'] ?? ''),
            'tags' => array_values(array_filter((array) ($preset['tags'] ?? []), 'is_string')),
            'lineage_name' => $lineageName,
            'resource_name' => $resourceName,
            'status' => $status,
            'status_label' => match ($status) {
                'COMPLETE' => '完成',
                'LOWER_VARIANT_AVAILABLE' => '現在版あり',
                default => '未完成',
            },
            'current_variant' => $current,
            'next_variant' => $next,
            'completion_variant' => $completion,
            'arts' => (array) ($target['arts'] ?? []),
            'slot_count' => (int) ($target['learned_count'] ?? 0),
            'empty_slots' => max(0, $this->jobArtService->maxSlots() - (int) ($target['learned_count'] ?? 0)),
            'cost' => (int) ($target['cost'] ?? 0),
            'can_apply' => $current !== null,
            'unavailable_reason' => $reason,
        ];
    }

    /**
     * @param Collection<string, array<string, mixed>> $variants
     * @return array<string, mixed>|null
     */
    private function nextVariant(Collection $variants, ?string $currentVariant): ?array
    {
        $order = $this->officialPresetCatalog->variants();
        $start = $currentVariant !== null ? array_search($currentVariant, $order, true) + 1 : 0;
        foreach (array_slice($order, $start) as $variant) {
            $configuration = $variants->get($variant);
            if (is_array($configuration) && ! $configuration['can_apply']) {
                return $configuration;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function variantConfiguration(
        Character $character,
        string $lineage,
        string $style,
        string $variant,
        string $slotContext,
    ): array {
        $definition = $this->officialPresetCatalog->variant($lineage, $style, $variant);
        $skillKeys = array_values(array_filter((array) ($definition['skills'] ?? []), 'is_string'));
        $configuredConditions = (array) ($definition['conditions'] ?? []);
        $availableArts = $this->availableArts($character, $slotContext);
        $available = $availableArts
            ->keyBy(fn (Skill $skill): string => $this->officialPresetCatalog->skillKey($skill));
        $masterArts = $this->masterArts();
        $slots = [];
        $conditions = [];
        $arts = [];
        $cost = 0;
        $definitionError = null;

        if ($definition === null || count($skillKeys) !== $this->jobArtService->maxSlots()
            || count(array_unique($skillKeys)) !== count($skillKeys)
        ) {
            $definitionError = '公式プリセットの定義が不完全です。';
        }

        foreach ($skillKeys as $index => $skillKey) {
            $slotNo = $index + 1;
            /** @var Skill|null $master */
            $master = $masterArts->get($skillKey);
            /** @var Skill|null $learned */
            $learned = $available->get($skillKey);
            if (! $master instanceof Skill
                || ($this->lineageCatalog->forArt($master)['lineage_key'] ?? null) !== $lineage
            ) {
                $definitionError ??= "公式プリセットの戦技定義（{$skillKey}）を現行マスタで確認できません。";
            }

            $skill = $learned instanceof Skill ? $learned : $master;
            $rank = $skill instanceof Skill ? (int) $skill->learn_rank : $this->rankFromSkillKey($skillKey);
            $artCost = $this->formalLineageCost($rank);
            $condition = (string) ($configuredConditions[$skillKey] ?? JobArtV2SlotConditionCatalog::ALWAYS);
            if (! $this->slotConditionCatalog->isAllowed($condition)) {
                $definitionError ??= "公式プリセットの条件（{$condition}）を使用できません。";
                $condition = JobArtV2SlotConditionCatalog::ALWAYS;
            }

            $display = $learned instanceof Skill
                ? $this->loadoutPresenter->forArt((int) $character->current_job_id, $learned)
                : [];
            $isLearned = $learned instanceof Skill;
            if ($isLearned) {
                $slots[$slotNo] = (int) $learned->id;
            }
            $conditions[$slotNo] = $condition;
            $cost += $artCost;
            $arts[] = [
                'slot_no' => $slotNo,
                'skill_key' => $skillKey,
                'skill_id' => $isLearned ? (int) $learned->id : null,
                'name' => $skill instanceof Skill ? (string) $skill->name : "戦技 {$skillKey}",
                'job_name' => $skill?->jobClass?->name ?? '',
                'rank' => $rank,
                'cost' => $artCost,
                'is_learned' => $isLearned,
                'origin_label' => $isLearned
                    ? (($learned->getAttribute('job_art_origin') === 'current') ? '現在職' : '継承')
                    : '未習得',
                'role_label' => (string) ($display['role_label'] ?? $this->roleLabel($rank)),
                'resource_text' => $display['resource_text'] ?? null,
                'condition_key' => $condition,
                'condition_label' => $this->slotConditionCatalog->labels()[$condition] ?? '条件なし',
            ];
        }

        $learnedCount = count($slots);
        $canApply = $definitionError === null
            && $learnedCount === $this->jobArtService->maxSlots()
            && $cost <= $this->jobArtService->maxCost();
        $unavailableReason = $definitionError;
        if ($canApply) {
            try {
                $this->jobArtService->validateSlotConfigurationAgainstAvailableArts(
                    $character,
                    $slots,
                    $slotContext,
                    $availableArts,
                );
            } catch (ValidationException $exception) {
                $canApply = false;
                $unavailableReason = collect($exception->errors())->flatten()->first()
                    ?: '現在の条件では適用できません。';
            }
        } elseif ($unavailableReason === null) {
            $unavailableReason = $cost > $this->jobArtService->maxCost()
                ? '公式プリセットのCostが上限を超えています。'
                : 'この構成に必要な5つの戦技をすべて習得してください。';
        }

        return [
            'key' => $variant,
            'label' => $this->officialPresetCatalog->variantLabel($variant),
            'slots' => $slots,
            'conditions' => $conditions,
            'arts' => $arts,
            'cost' => $cost,
            'learned_count' => $learnedCount,
            'missing_count' => max(0, $this->jobArtService->maxSlots() - $learnedCount),
            'can_apply' => $canApply,
            'unavailable_reason' => $unavailableReason,
        ];
    }

    /** @return Collection<int, Skill> */
    private function availableArts(Character $character, string $slotContext): Collection
    {
        $availabilityContext = $this->jobArtService->availabilityContextForSlotContext($slotContext);
        $cacheKey = implode(':', [
            (int) ($character->id ?? 0),
            (int) ($character->current_job_id ?? 0),
            $availabilityContext,
        ]);

        return $this->availableArtsCache[$cacheKey] ??= $this->jobArtService
            ->availableArts($character, $availabilityContext)
            ->filter(fn ($art): bool => $art instanceof Skill)
            ->values();
    }

    /** @return Collection<string, Skill> */
    private function masterArts(): Collection
    {
        return $this->masterArtsCache ??= Skill::query()
            ->where('skill_type', 'job_art')
            ->with('jobClass')
            ->get()
            ->keyBy(fn (Skill $skill): string => $this->officialPresetCatalog->skillKey($skill));
    }

    private function resourceNameForPreset(string $lineage, string $style): string
    {
        $definition = $this->officialPresetCatalog->variant(
            $lineage,
            $style,
            JobArtV2OfficialPresetCatalog::CROWN,
        );
        $skillKey = collect((array) ($definition['skills'] ?? []))->first();
        if (! is_string($skillKey)) {
            return '';
        }

        $skill = $this->masterArts()->get($skillKey);
        if (! $skill instanceof Skill) {
            return '';
        }

        return (string) ($this->resourceCatalog->forArt($skill)['resource_name'] ?? '');
    }

    private function formalLineageCost(int $rank): int
    {
        return match ($rank) {
            1 => 1,
            5 => 2,
            9 => 3,
            default => 99,
        };
    }

    private function roleLabel(int $rank): string
    {
        return match ($rank) {
            1 => '始動',
            5 => '連携',
            9 => '奥義',
            default => '戦技',
        };
    }

    private function rankFromSkillKey(string $skillKey): int
    {
        $parts = explode(':', $skillKey, 2);

        return isset($parts[1]) ? (int) $parts[1] : 0;
    }

    private function validStyle(string $style): bool
    {
        return in_array($style, $this->officialPresetCatalog->styles(), true);
    }

    private function validLineage(string $lineage): bool
    {
        return in_array($lineage, $this->officialPresetCatalog->lineages(), true);
    }

    private function validContext(string $slotContext): bool
    {
        return in_array($slotContext, $this->jobArtService->slotContexts(), true);
    }
}
