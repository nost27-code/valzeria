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
}
