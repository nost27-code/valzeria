<?php

namespace App\Services\Nation\Raid;

use InvalidArgumentException;

/** 独立seedを使い、PHPのglobal乱数状態を変更しない。 */
final class NationRaidSeededRandom implements NationRaidRandomSource
{
    private int $state;

    public function __construct(int $seed)
    {
        $this->state = $seed & 0xFFFFFFFF;
        if ($this->state === 0) {
            $this->state = 0x6D2B79F5;
        }
    }

    public function nextInt(int $minimum, int $maximum): int
    {
        if ($minimum > $maximum) {
            throw new InvalidArgumentException('Random minimum must not exceed maximum.');
        }

        $this->state = (int) (($this->state * 1_664_525 + 1_013_904_223) & 0xFFFFFFFF);
        $range = $maximum - $minimum + 1;

        return $minimum + ($this->state % $range);
    }
}
