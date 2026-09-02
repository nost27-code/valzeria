<?php

namespace App\Services\Battle;

use Closure;
use Random\Randomizer;

final class ScopedBattleRandomizer
{
    /** @var list<Randomizer> */
    private static array $stack = [];

    /**
     * @template T
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function run(Randomizer $randomizer, Closure $callback): mixed
    {
        self::$stack[] = $randomizer;

        try {
            return $callback();
        } finally {
            array_pop(self::$stack);
        }
    }

    public static function percentRoll(): int
    {
        return self::int(1, 100);
    }

    public static function int(int $min, int $max): int
    {
        $randomizer = self::$stack[array_key_last(self::$stack)] ?? null;

        return $randomizer?->getInt($min, $max) ?? random_int($min, $max);
    }
}
