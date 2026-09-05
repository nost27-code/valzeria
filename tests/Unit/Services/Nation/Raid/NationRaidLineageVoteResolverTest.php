<?php

namespace Tests\Unit\Services\Nation\Raid;

use App\Services\Nation\Raid\NationRaidLineageVoteResolver;
use App\Services\Nation\Raid\Simulation\NationRaidSimulationLineageAdapter;
use PHPUnit\Framework\TestCase;

final class NationRaidLineageVoteResolverTest extends TestCase
{
    public function test_same_event_seed_keeps_tie_order_and_zero_votes_select_nothing(): void
    {
        $resolver = new NationRaidLineageVoteResolver(new NationRaidSimulationLineageAdapter);
        $seed = str_repeat('a', 64);
        $day2 = $resolver->resolve(['aim' => 2, 'hunt' => 2], $seed);
        $day3 = $resolver->resolve(['hunt' => 9, 'aim' => 9], $seed);
        $this->assertSame($day2['selected'], $day3['selected']);
        $this->assertSame($day2['order'], $day3['order']);
        $this->assertNull($resolver->resolve([], $seed)['selected']);
        $this->assertSame('break', $resolver->resolve(['aim' => 2, 'break' => 3], $seed)['selected']);
        $this->assertSame(10, count($day2['counts']));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $resolver->contractHash());
    }

    public function test_unknown_lineage_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new NationRaidLineageVoteResolver(new NationRaidSimulationLineageAdapter))->resolve(['unknown' => 1], str_repeat('a', 64));
    }
}
