<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterJobArtSlot;
use App\Models\Skill;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JobArtService
{
    public const MAX_SLOTS = 3;
    public const MAX_COST = 5;
    public const ACTIVATION_POLICIES = ['aggressive', 'normal', 'conserve', 'boss_only'];

    public function activationPolicyLabels(): array
    {
        return [
            'aggressive' => '積極',
            'normal' => '通常',
            'conserve' => '温存',
            'boss_only' => 'ボス用',
        ];
    }

    public function activationPolicyDescriptions(): array
    {
        return [
            'aggressive' => 'SPが足りていれば発動します',
            'normal' => 'SPが30%以上ある時だけ発動します',
            'conserve' => 'SPが60%以上ある時だけ発動します',
            'boss_only' => '通常探索では発動せず、ボス戦やチャンプ戦で発動します',
        ];
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
                $skill->setAttribute('job_art_rate', $availability['rate']);
                return $skill;
            })
            ->values();
    }

    public function selectedSlots(Character $character, string $context = 'pve'): Collection
    {
        $availableIds = $this->availableArts($character, $context)->pluck('id')->all();

        return $character->jobArtSlots()
            ->with('skill.jobClass')
            ->orderBy('slot_no')
            ->get()
            ->filter(fn (CharacterJobArtSlot $slot): bool => $slot->skill && in_array($slot->skill_id, $availableIds, true))
            ->values();
    }

    public function battleArtsFor(Character $character, string $context = 'pve'): Collection
    {
        $available = $this->availableArts($character, $context)->keyBy('id');

        return $character->jobArtSlots()
            ->with('skill.jobClass')
            ->orderBy('slot_no')
            ->get()
            ->map(function (CharacterJobArtSlot $slot) use ($available) {
                $skill = $available->get($slot->skill_id);
                if (!$skill) {
                    return null;
                }

                $skill->setAttribute('slot_no', (int) $slot->slot_no);
                return $skill;
            })
            ->filter()
            ->values();
    }

    public function saveSlots(Character $character, array $slotSkillIds): void
    {
        $normalized = $this->normalizeSlotInput($slotSkillIds);
        $this->validateSlots($character, $normalized);

        DB::transaction(function () use ($character, $normalized) {
            $character->jobArtSlots()->delete();
            foreach ($normalized as $slotNo => $skillId) {
                CharacterJobArtSlot::create([
                    'character_id' => $character->id,
                    'slot_no' => $slotNo,
                    'skill_id' => $skillId,
                ]);
            }
        });
    }

    public function assignToSlot(Character $character, int $skillId, ?int $slotNo): void
    {
        $slots = $this->selectedSlots($character, 'pve')
            ->mapWithKeys(fn (CharacterJobArtSlot $slot): array => [(int) $slot->slot_no => (int) $slot->skill_id])
            ->all();

        foreach ($slots as $existingSlotNo => $existingSkillId) {
            if ($existingSkillId === $skillId || ($slotNo !== null && $existingSlotNo === $slotNo)) {
                unset($slots[$existingSlotNo]);
            }
        }

        if ($slotNo !== null) {
            $slots[$slotNo] = $skillId;
        }

        ksort($slots);
        $this->saveSlots($character, $slots);
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
        return (int) $skills->sum(fn (Skill $skill): int => (int) $skill->art_cost);
    }

    public function contextAllows(Skill $skill, string $context): bool
    {
        return match ($context) {
            'boss' => (bool) $skill->boss_enabled,
            'champ' => (bool) $skill->champ_enabled && !$skill->isRewardArt(),
            default => (bool) $skill->pve_enabled,
        };
    }

    private function validateSlots(Character $character, array $slotSkillIds): void
    {
        if (count($slotSkillIds) > self::MAX_SLOTS) {
            throw ValidationException::withMessages(['slots' => '奥義は最大3つまで設定できます。']);
        }

        $available = $this->availableArts($character, 'pve')->keyBy('id');
        $selected = collect();
        $seen = [];

        foreach ($slotSkillIds as $slotNo => $skillId) {
            if ($slotNo < 1 || $slotNo > self::MAX_SLOTS) {
                throw ValidationException::withMessages(['slots' => '奥義枠は1〜3のみ使用できます。']);
            }
            if (isset($seen[$skillId])) {
                throw ValidationException::withMessages(['slots' => '同じ奥義を複数セットすることはできません。']);
            }
            $seen[$skillId] = true;

            $skill = $available->get($skillId);
            if (!$skill) {
                throw ValidationException::withMessages(['slots' => 'この奥義はまだ習得していません。']);
            }
            if ($skill->isTimeLimited() && $skill->getAttribute('job_art_origin') !== 'current') {
                throw ValidationException::withMessages(['slots' => '時空系の奥義は時空王でのみ使用できます。']);
            }

            $selected->push($skill);
        }

        if ($this->totalCost($selected) > self::MAX_COST) {
            throw ValidationException::withMessages(['slots' => '奥義コストの合計は5までです。']);
        }

        foreach (['HEAL' => '回復系の奥義は1つまでしか設定できません。', 'REWARD' => '報酬系の奥義は1つまでしか設定できません。', 'TIME' => '時空系の奥義は時空王でのみ使用できます。', 'GUTS' => '踏みとどまり系の奥義は1つまでしか設定できません。'] as $group => $message) {
            if ($selected->where('limit_group', $group)->count() > 1) {
                throw ValidationException::withMessages(['slots' => $message]);
            }
        }
    }

    private function normalizeSlotInput(array $slotSkillIds): array
    {
        $normalized = [];
        foreach ($slotSkillIds as $slotNo => $skillId) {
            $slotNo = (int) $slotNo;
            $skillId = (int) $skillId;
            if ($slotNo < 1 || $slotNo > self::MAX_SLOTS || $skillId <= 0) {
                continue;
            }
            $normalized[$slotNo] = $skillId;
        }

        ksort($normalized);
        return $normalized;
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
