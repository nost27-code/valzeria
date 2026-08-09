<?php

namespace App\Services;

use App\Models\Skill;

final class JobArtV2DamageSemanticsCatalog
{
    /**
     * Prototype runtime overrides. Legacy master semantics remain unchanged.
     *
     * @var array<int, array<int, array{attack_stat: string, defense_stat: string, damage_category: string}>>
     */
    private const OVERRIDES = [
        61 => [
            1 => ['attack_stat' => 'mag', 'defense_stat' => 'spr', 'damage_category' => 'magical'],
            5 => ['attack_stat' => 'mag', 'defense_stat' => 'spr', 'damage_category' => 'magical'],
            9 => ['attack_stat' => 'mag', 'defense_stat' => 'spr', 'damage_category' => 'magical'],
        ],
    ];

    /** @return array{attack_stat: string, defense_stat: string, damage_category: string}|null */
    public function overrideFor(Skill $skill): ?array
    {
        return self::OVERRIDES[(int) $skill->job_id][(int) $skill->learn_rank] ?? null;
    }
}
