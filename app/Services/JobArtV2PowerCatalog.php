<?php

namespace App\Services;

use App\Models\Skill;

class JobArtV2PowerCatalog
{
    /**
     * Prototype-only calibration values. Legacy masters remain authoritative
     * whenever the required v2 feature chain is not fully available.
     *
     * @var array<int, array<int, array{power: int, requires: string, scope?: string}>>
     */
    private const OVERRIDES = [
        // L列の355%を基礎値とし、系譜を問わず連携を使用済みの場合だけ470%へ変更する。
        62 => [
            9 => ['power' => 470, 'requires' => 'penetration'],
        ],
    ];

    /** @return array{power: int, requires: string, scope?: string}|null */
    public function overrideFor(Skill $skill): ?array
    {
        return self::OVERRIDES[(int) $skill->job_id][(int) $skill->learn_rank] ?? null;
    }
}
