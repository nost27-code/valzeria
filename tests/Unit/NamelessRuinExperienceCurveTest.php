<?php

namespace Tests\Unit;

use App\Services\NamelessRuinExperienceCurve;
use PHPUnit\Framework\TestCase;

class NamelessRuinExperienceCurveTest extends TestCase
{
    public function test_each_depth_increases_without_reducing_existing_rewards(): void
    {
        $base = array_fill(1, 100, 3979);
        $base[1] = 995;
        $base[40] = 6202;
        $curve = (new NamelessRuinExperienceCurve)->build($base, 1.5);

        $this->assertSame(995, $curve[1]);
        for ($depth = 2; $depth <= 100; $depth++) {
            $this->assertGreaterThan($curve[$depth - 1], $curve[$depth]);
            $this->assertGreaterThanOrEqual($base[$depth], $curve[$depth]);
        }
        $this->assertSame(6262, $curve[100]);
    }

    public function test_last_depth_targets_one_and_a_half_times_the_old_reward(): void
    {
        $base = [];
        for ($depth = 1; $depth <= 100; $depth++) {
            $base[$depth] = (int) round(955 + (3979 - 955) * ($depth - 1) / 99);
        }
        $curve = (new NamelessRuinExperienceCurve)->build($base, 1.5);

        $this->assertSame(955, $curve[1]);
        $this->assertSame(5969, $curve[100]);
        $this->assertCount(100, $curve);
    }

    public function test_single_depth_preserves_its_existing_reward(): void
    {
        $this->assertSame([1 => 955], (new NamelessRuinExperienceCurve)->build([1 => 955], 1.5));
    }
}
