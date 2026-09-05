<?php

namespace App\Services\Nation\Raid\Simulation;

use App\Models\Character;
use Illuminate\Container\Attributes\Bind;

#[Bind(NationRaidPassiveBossActionProfileProvider::class)]
interface NationRaidSimulationActionProfileProvider
{
    /**
     * @return list<array{profile_no:int,actions:list<array<string, mixed>>}>
     */
    public function profilesFor(Character $character, int $profileCount): array;

    public function modelVersion(): string;

    /**
     * Phase 2のbalance合否に使える精度か。falseならsweepは参考値に限定する。
     */
    public function authoritativeForBalanceGate(): bool;
}
