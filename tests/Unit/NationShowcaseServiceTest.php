<?php

namespace Tests\Unit;

use App\Services\Nation\NationShowcaseService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class NationShowcaseServiceTest extends TestCase
{
    public function test_daily_rotation_is_stable_and_gives_every_nation_equal_exposure(): void
    {
        $service = new NationShowcaseService;
        $nationIds = [50, 10, 40, 20, 30];
        $firstDay = CarbonImmutable::create(2026, 1, 1, 9, 0, 0, 'Asia/Tokyo');

        $this->assertSame([10, 20, 30], $service->rotateForDay($nationIds, $firstDay));
        $this->assertSame([10, 20, 30], $service->rotateForDay($nationIds, $firstDay->endOfDay()));
        $this->assertSame([20, 30, 40], $service->rotateForDay($nationIds, $firstDay->addDay()));
        $this->assertSame([50, 10, 20], $service->rotateForDay($nationIds, $firstDay->addDays(4)));

        $appearances = array_fill_keys([10, 20, 30, 40, 50], 0);
        $positionAppearances = array_fill(0, 3, $appearances);
        for ($day = 0; $day < count($nationIds); $day++) {
            foreach ($service->rotateForDay($nationIds, $firstDay->addDays($day)) as $position => $nationId) {
                $appearances[$nationId]++;
                $positionAppearances[$position][$nationId]++;
            }
        }

        $this->assertSame([10 => 3, 20 => 3, 30 => 3, 40 => 3, 50 => 3], $appearances);
        foreach ($positionAppearances as $positionAppearance) {
            $this->assertSame([10 => 1, 20 => 1, 30 => 1, 40 => 1, 50 => 1], $positionAppearance);
        }
    }

    public function test_rotation_handles_zero_to_three_nations_without_duplicates(): void
    {
        $service = new NationShowcaseService;
        $day = CarbonImmutable::create(2026, 1, 1, 12, 0, 0, 'Asia/Tokyo');

        $this->assertSame([], $service->rotateForDay([], $day));
        $this->assertSame([10], $service->rotateForDay([10], $day));
        $this->assertSame([10, 20], $service->rotateForDay([20, 10, 20], $day));
        $this->assertSame([10, 20, 30], $service->rotateForDay([30, 10, 20], $day));
        $this->assertSame([], $service->rotateForDay([10, 20, 30], $day, 0));
    }
}
