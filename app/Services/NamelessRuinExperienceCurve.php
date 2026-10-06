<?php

namespace App\Services;

class NamelessRuinExperienceCurve
{
    /** @param array<int, int> $baseExperiences 深度1から最深部までの従来EXP。 */
    public function build(array $baseExperiences, float $maxDepthMultiplier): array
    {
        $maxDepth = count($baseExperiences);
        $first = $baseExperiences[1];
        $target = max($first, (int) round($baseExperiences[$maxDepth] * max(1, $maxDepthMultiplier)));
        $experiences = [1 => $first];

        for ($depth = 2; $depth <= $maxDepth; $depth++) {
            $interpolated = (int) round($first + ($target - $first) * ($depth - 1) / ($maxDepth - 1));
            // 参照敵の切替で減らさず、従来EXPと直前深度+1を下限とする。
            $experiences[$depth] = max($baseExperiences[$depth], $interpolated, $experiences[$depth - 1] + 1);
        }

        return $experiences;
    }
}
