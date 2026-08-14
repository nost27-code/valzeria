<?php

namespace App\Services\Battle;

final class ParryResult
{
    public int $damageBeforeParry = 0;
    public int $damageAfterParry = 0;
    public int $counterPower = 0;
    public int $counterDamage = 0;

    public function __construct(
        public readonly int $sourceActionId,
        public readonly string $attackerKey,
        public readonly string $targetKey,
        public readonly bool $eligible,
        public readonly bool $rolled,
        public readonly bool $success,
        public readonly float $rate,
    ) {}

    public function recordHit(int $before, int $after): void
    {
        $this->damageBeforeParry += max(0, $before);
        $this->damageAfterParry += max(0, $after);
    }

    public function recordCounter(int $power, int $damage): void
    {
        $this->counterPower = max(0, $power);
        $this->counterDamage = max(0, $damage);
    }

    /** @return array<string, bool|float|int|string> */
    public function toArray(): array
    {
        return [
            'source_action_id' => $this->sourceActionId,
            'attacker_key' => $this->attackerKey,
            'target_key' => $this->targetKey,
            'eligible' => $this->eligible,
            'rolled' => $this->rolled,
            'success' => $this->success,
            'rate' => $this->rate,
            'damage_before_parry' => $this->damageBeforeParry,
            'damage_after_parry' => $this->damageAfterParry,
            'counter_power' => $this->counterPower,
            'counter_damage' => $this->counterDamage,
        ];
    }
}
