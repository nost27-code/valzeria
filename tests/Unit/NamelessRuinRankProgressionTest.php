<?php

namespace Tests\Unit;

use App\Services\NamelessRuinService;
use Tests\TestCase;

class NamelessRuinRankProgressionTest extends TestCase
{
    public function test_each_depth_improves_every_higher_rank_tail_through_depth_100(): void
    {
        $ruins = app(NamelessRuinService::class);
        $previous = $ruins->rankWeights(1);

        for ($depth = 2; $depth <= 100; $depth++) {
            $current = $ruins->rankWeights($depth);
            for ($minimumRank = 2; $minimumRank <= 9; $minimumRank++) {
                $previousTail = array_sum(array_slice($previous, $minimumRank - 1));
                $currentTail = array_sum(array_slice($current, $minimumRank - 1));
                $this->assertGreaterThan(
                    $previousTail * array_sum($current),
                    $currentTail * array_sum($previous),
                    "Depth {$depth}: rank {$minimumRank}+ must be more likely than at the previous depth."
                );
            }
            $previous = $current;
        }
    }

    public function test_existing_shallow_distribution_and_approved_deep_distribution(): void
    {
        $ruins = app(NamelessRuinService::class);
        $this->assertSame([1 => 48000, 28000, 14000, 6000, 2500, 1000, 400, 90, 10], $ruins->rankWeights(1));
        $this->assertSame([1 => 48000, 36400, 23660, 13182, 7140, 3713, 1931, 565, 82], $ruins->rankWeights(31));

        // The approved proposal: depth 100 has approximately 12.19% VII+ and 0.780% IX.
        $weights = $ruins->rankWeights(100);
        $total = array_sum($weights);
        $this->assertEqualsWithDelta(12.1871, 100 * array_sum(array_slice($weights, 6)) / $total, .0001);
        $this->assertEqualsWithDelta(.7799, 100 * $weights[9] / $total, .0001);
        $this->assertGreaterThan($weights[1], $weights[2]);
        $this->assertGreaterThan($weights[1], $weights[3]);
        $this->assertSame($weights, $ruins->rankWeights(101));
    }
}
