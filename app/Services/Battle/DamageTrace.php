<?php

namespace App\Services\Battle;

final class DamageTrace
{
    public int $damageBeforeActiveGuard = 0;
    public int $damageAfterActiveGuard = 0;
    public int $preventedDamage = 0;

    public function __construct(
        public readonly int $sourceActionId,
        public readonly string $attackerKey,
        public readonly string $targetKey,
        public readonly float $guardRate,
        public readonly bool $guardConsumed,
    ) {}

    public function recordHit(int $before, int $after): void
    {
        $before = max(0, $before);
        $after = max(0, $after);
        $this->damageBeforeActiveGuard += $before;
        $this->damageAfterActiveGuard += $after;
        $this->preventedDamage += max(0, $before - $after);
    }

    /** @return array<string, bool|float|int|string> */
    public function toArray(): array
    {
        return [
            'source_action_id' => $this->sourceActionId,
            'attacker_key' => $this->attackerKey,
            'target_key' => $this->targetKey,
            'guard_rate' => $this->guardRate,
            'guard_consumed' => $this->guardConsumed,
            'damage_before_active_guard' => $this->damageBeforeActiveGuard,
            'damage_after_active_guard' => $this->damageAfterActiveGuard,
            'prevented_damage' => $this->preventedDamage,
        ];
    }
}
