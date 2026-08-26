<?php

namespace App\Services\Nation;

final class NationDevelopmentLevelService
{
    public function maxLevel(): int
    {
        return (int) config('nation_development.max_level', 50);
    }

    public function levelFor(int $experience): int
    {
        $experience = max(0, $experience);

        for ($level = 2; $level <= $this->maxLevel(); $level++) {
            if ($experience < $this->cumulativeExpForLevel($level)) {
                return $level - 1;
            }
        }

        return $this->maxLevel();
    }

    public function cumulativeExpForLevel(int $level): int
    {
        throw_if($level < 1 || $level > $this->maxLevel(), \InvalidArgumentException::class, '国家レベルが範囲外です。');

        $multiplier = (int) config('nation_development.next_level_exp_multiplier', 500);

        return intdiv($multiplier * $level * ($level - 1), 2);
    }

    /**
     * @return array{
     *   level:int,
     *   member_capacity:int,
     *   facility_level_cap:int,
     *   goal_slots:int,
     *   wanted_material_slots:int,
     *   showcase_slots:int,
     *   war_preset_slots:int,
     *   features:list<string>
     * }
     */
    public function benefitsForLevel(int $level): array
    {
        $level = max(1, min($this->maxLevel(), $level));
        $resolved = [
            'level' => $level,
            'member_capacity' => 20,
            'facility_level_cap' => 5,
            'goal_slots' => 1,
            'wanted_material_slots' => 1,
            'showcase_slots' => 0,
            'war_preset_slots' => 0,
            'features' => [],
        ];

        foreach ($this->milestones() as $requiredLevel => $milestone) {
            if ($requiredLevel > $level) {
                break;
            }

            foreach (['member_capacity', 'facility_level_cap', 'goal_slots', 'wanted_material_slots', 'showcase_slots', 'war_preset_slots'] as $key) {
                if (array_key_exists($key, $milestone)) {
                    $resolved[$key] = (int) $milestone[$key];
                }
            }
            $resolved['features'] = array_values(array_unique([
                ...$resolved['features'],
                ...array_values(array_map('strval', $milestone['features'] ?? [])),
            ]));
        }

        return $resolved;
    }

    public function memberCapacityForExperience(int $experience): int
    {
        return $this->benefitsForLevel($this->levelFor($experience))['member_capacity'];
    }

    /** @return list<array{from_level:int,to_level:int,member_capacity:int}> */
    public function memberCapacityRanges(): array
    {
        $ranges = [];
        $fromLevel = 1;
        $capacity = $this->benefitsForLevel(1)['member_capacity'];

        for ($level = 2; $level <= $this->maxLevel(); $level++) {
            $levelCapacity = $this->benefitsForLevel($level)['member_capacity'];
            if ($levelCapacity === $capacity) {
                continue;
            }

            $ranges[] = [
                'from_level' => $fromLevel,
                'to_level' => $level - 1,
                'member_capacity' => $capacity,
            ];
            $fromLevel = $level;
            $capacity = $levelCapacity;
        }

        $ranges[] = [
            'from_level' => $fromLevel,
            'to_level' => $this->maxLevel(),
            'member_capacity' => $capacity,
        ];

        return $ranges;
    }

    public function facilityLevelCapForExperience(int $experience): int
    {
        return $this->benefitsForLevel($this->levelFor($experience))['facility_level_cap'];
    }

    /** @return array{level:int,cumulative_exp:int,label:string}|null */
    public function nextBenefitAfterLevel(int $level): ?array
    {
        foreach ($this->milestones() as $requiredLevel => $milestone) {
            if ($requiredLevel <= $level) {
                continue;
            }

            return [
                'level' => $requiredLevel,
                'cumulative_exp' => $this->cumulativeExpForLevel($requiredLevel),
                'label' => (string) ($milestone['label'] ?? "国家Lv{$requiredLevel}特典"),
            ];
        }

        return null;
    }

    public function hasFeature(int $level, string $feature): bool
    {
        return in_array($feature, $this->benefitsForLevel($level)['features'], true);
    }

    /** @return array{level:int,max_level:int,total_exp:int,current_level_exp:int,next_level_exp:?int,exp_into_level:int,exp_to_next:?int,progress_bps:int,is_max:bool} */
    public function progress(int $experience): array
    {
        $experience = max(0, $experience);
        $level = $this->levelFor($experience);
        $isMax = $level === $this->maxLevel();
        $currentLevelExp = $this->cumulativeExpForLevel($level);
        $nextLevelExp = $isMax ? null : $this->cumulativeExpForLevel($level + 1);
        $required = $nextLevelExp === null ? null : $nextLevelExp - $currentLevelExp;
        $expIntoLevel = max(0, $experience - $currentLevelExp);

        return [
            'level' => $level,
            'max_level' => $this->maxLevel(),
            'total_exp' => $experience,
            'current_level_exp' => $currentLevelExp,
            'next_level_exp' => $nextLevelExp,
            'exp_into_level' => $expIntoLevel,
            'exp_to_next' => $required === null ? null : max(0, $required - $expIntoLevel),
            'progress_bps' => $required === null ? 10000 : min(10000, intdiv($expIntoLevel * 10000, $required)),
            'is_max' => $isMax,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function milestones(): array
    {
        $milestones = (array) config('nation_development.benefit_milestones', []);
        ksort($milestones, SORT_NUMERIC);

        return $milestones;
    }
}
