<?php

namespace App\Services\Nation\Raid;

final readonly class NationRaidEnemyDamageResult
{
    /**
     * @param  list<array{index:int,type:string,power:int,outcome:string,critical:bool,variance:int,damage:int}>  $hits
     * @param  list<string>  $appliedEffects
     * @param  array<string, mixed>  $playerDefense
     */
    public function __construct(
        public int $beforeCap,
        public int $cap,
        public int $afterCap,
        public float $finalReductionRate,
        public int $finalDamage,
        public array $hits,
        public array $appliedEffects,
        public array $playerDefense = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
