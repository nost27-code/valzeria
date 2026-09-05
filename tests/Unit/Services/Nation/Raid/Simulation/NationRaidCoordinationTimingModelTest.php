<?php

namespace Tests\Unit\Services\Nation\Raid\Simulation;

use App\Services\Nation\Raid\Simulation\NationRaidCoordinationTimingModel;
use InvalidArgumentException;
use Tests\TestCase;

final class NationRaidCoordinationTimingModelTest extends TestCase
{
    public function test_event_minute_is_seeded_and_uses_only_the_characters_empirical_samples(): void
    {
        $model = app(NationRaidCoordinationTimingModel::class);
        $activity = ['minute_of_day_samples' => [60, 720, 1_380]];

        $first = $model->eventMinute($activity, 3, 2, 20260903, 'nrc2_test');
        $second = $model->eventMinute($activity, 3, 2, 20260903, 'nrc2_test');

        $this->assertSame($first, $second);
        $this->assertContains($first - (2 * 1_440), $activity['minute_of_day_samples']);
        $this->assertSame('nation-raid-coordination-empirical-minute-bootstrap-v1', $model->contract()['version']);
        $this->assertTrue($model->contract()['authoritative_for_balance_gate']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $model->contractHash());
    }

    public function test_registration_matches_three_hour_unique_character_contract_and_keeps_nations_independent(): void
    {
        $model = app(NationRaidCoordinationTimingModel::class);
        $active = [];

        $first = $model->register($active, 'nation-a', 'character-a', 1_000);
        $repeat = $model->register($active, 'nation-a', 'character-a', 1_050);
        $second = $model->register($active, 'nation-a', 'character-b', 1_179);
        $otherNation = $model->register($active, 'nation-b', 'character-c', 1_179);
        $boundary = $model->register($active, 'nation-a', 'character-d', 1_180);
        $unaffiliated = $model->register($active, null, 'character-e', 1_180);

        $this->assertSame([true, 1, 0.0, true], array_values($first));
        $this->assertSame([true, 1, 0.0, false], array_values($repeat));
        $this->assertSame([true, 2, 0.03, true], array_values($second));
        $this->assertSame([true, 1, 0.0, true], array_values($otherNation));
        $this->assertSame([true, 2, 0.03, true], array_values($boundary));
        $this->assertSame([false, 0, 0.0, false], array_values($unaffiliated));
        $this->assertArrayNotHasKey('character-a', $active['nation-a']);
        $this->assertSame(300, $model->coordinationDamage(10_001, 0.03));
    }

    public function test_missing_empirical_timing_samples_fail_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('timing samples are missing');

        app(NationRaidCoordinationTimingModel::class)->eventMinute([], 1, 1, 1, 'character');
    }
}
