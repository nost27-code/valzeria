<?php

namespace App\Services\Nation\Raid;

final class NationRaidBossSpState
{
    private int $current = NationRaidRules::BOSS_MAX_SP;

    private int $recoverySlowCharges = 0;

    private int $reservationFailureCount = 0;

    /** @var list<array{turn:int,event:string,before:int,after:int,amount:int}> */
    private array $trace = [];

    public function current(): int
    {
        return $this->current;
    }

    public function reserve(int $turn): bool
    {
        $before = $this->current;
        if ($this->current < NationRaidRules::RESERVATION_SP_COST) {
            $this->reservationFailureCount++;
            $this->record($turn, 'reservation_failed', $before, $before, 0);

            return false;
        }

        $this->current -= NationRaidRules::RESERVATION_SP_COST;
        $this->record($turn, 'reservation', $before, $this->current, -NationRaidRules::RESERVATION_SP_COST);

        return true;
    }

    public function completedAction(int $turn): int
    {
        $before = $this->current;
        $recovery = $this->recoverySlowCharges > 0
            ? NationRaidRules::ACTION_SP_RECOVERY - 1
            : NationRaidRules::ACTION_SP_RECOVERY;
        if ($this->recoverySlowCharges > 0) {
            $this->recoverySlowCharges--;
        }
        $this->current = min(NationRaidRules::BOSS_MAX_SP, $this->current + $recovery);
        $this->record($turn, 'action_recovery', $before, $this->current, $this->current - $before);

        return $this->current - $before;
    }

    public function reduce(int $turn, int $amount, string $reason): int
    {
        $before = $this->current;
        $this->current = max(0, $this->current - max(0, $amount));
        $this->record($turn, $reason, $before, $this->current, $this->current - $before);

        return $before - $this->current;
    }

    public function applyRecoverySlow(int $turn, int $charges): void
    {
        $this->recoverySlowCharges = max($this->recoverySlowCharges, max(0, $charges));
        $this->record($turn, 'recovery_slow', $this->current, $this->current, $this->recoverySlowCharges);
    }

    public function consumeUltimate(int $turn): bool
    {
        $before = $this->current;
        if ($before < NationRaidRules::ULTIMATE_SP_COST) {
            $this->record($turn, 'ultimate_insufficient', $before, $before, 0);

            return false;
        }

        $this->current -= NationRaidRules::ULTIMATE_SP_COST;
        $this->record($turn, 'ultimate_cost', $before, $this->current, -NationRaidRules::ULTIMATE_SP_COST);

        return true;
    }

    public function recordNoAction(int $turn, string $reason): void
    {
        $this->record($turn, $reason, $this->current, $this->current, 0);
    }

    public function reservationFailureCount(): int
    {
        return $this->reservationFailureCount;
    }

    /** @return list<array{turn:int,event:string,before:int,after:int,amount:int}> */
    public function trace(): array
    {
        return $this->trace;
    }

    private function record(int $turn, string $event, int $before, int $after, int $amount): void
    {
        $this->trace[] = compact('turn', 'event', 'before', 'after', 'amount');
    }
}
