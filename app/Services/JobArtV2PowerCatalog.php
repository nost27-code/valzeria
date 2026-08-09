<?php

namespace App\Services;

use App\Models\Skill;

class JobArtV2PowerCatalog
{
    /**
     * Prototype-only calibration values. Legacy masters remain authoritative
     * whenever the required v2 feature chain is not fully available.
     *
     * @var array<int, array<int, array{power: int, requires: string}>>
     */
    private const OVERRIDES = [
        53 => [
            9 => ['power' => 410, 'requires' => 'resources'],
        ],
        60 => [
            9 => ['power' => 455, 'requires' => 'resources'],
        ],
        61 => [
            9 => ['power' => 585, 'requires' => 'resources'],
        ],
        62 => [
            9 => ['power' => 470, 'requires' => 'penetration'],
        ],
        64 => [
            9 => ['power' => 460, 'requires' => 'resources'],
        ],
        65 => [
            9 => ['power' => 570, 'requires' => 'resources'],
        ],
        69 => [
            9 => ['power' => 455, 'requires' => 'resources'],
        ],
    ];

    /** @return array{power: int, requires: string}|null */
    public function overrideFor(Skill $skill): ?array
    {
        return self::OVERRIDES[(int) $skill->job_id][(int) $skill->learn_rank] ?? null;
    }
}
