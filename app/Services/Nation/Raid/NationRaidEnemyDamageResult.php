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
        public ?int $finalDamageWithoutRelicMitigation = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = get_object_vars($this);
        // 旧snapshot・遺物OFFの表示契約へ任意の表示情報を強制しない。
        if ($this->finalDamageWithoutRelicMitigation === null) {
            unset($data['finalDamageWithoutRelicMitigation']);
        }

        return $data;
    }

    public function withPlayerDefense(array $defense): self
    {
        return new self($this->beforeCap, $this->cap, $this->afterCap, $this->finalReductionRate,
            $this->finalDamage, $this->hits, $this->appliedEffects, $defense, $this->finalDamageWithoutRelicMitigation);
    }
}
