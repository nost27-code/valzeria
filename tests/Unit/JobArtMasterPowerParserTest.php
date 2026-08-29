<?php

namespace Tests\Unit;

use App\Support\JobArtMasterPowerParser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class JobArtMasterPowerParserTest extends TestCase
{
    #[DataProvider('powerHints')]
    public function test_master_power_hint_is_parsed_by_the_shared_rule(mixed $hint, int $expected): void
    {
        $this->assertSame($expected, JobArtMasterPowerParser::parse($hint));
    }

    /** @return array<string, array{mixed, int}> */
    public static function powerHints(): array
    {
        return [
            'integer' => [225, 225],
            'numeric string' => ['145', 145],
            'labelled support power' => ['補助100', 100],
            'labelled recovery power' => ['回復110相当', 110],
            'negative numeric value is clamped' => [-20, 0],
            'missing descriptive value uses legacy default' => ['補助', 100],
        ];
    }
}
