<?php

namespace Tests\Unit\Services\Nation\Raid\Simulation;

use App\Services\Nation\Raid\Simulation\NationRaidSimulationAnonymizer;
use PHPUnit\Framework\TestCase;

class NationRaidSimulationAnonymizerTest extends TestCase
{
    public function test_keys_are_stable_scoped_and_do_not_contain_database_ids(): void
    {
        $anonymizer = new NationRaidSimulationAnonymizer('base64:test-secret');

        $character = $anonymizer->characterKey(123456789);
        $this->assertSame($character, $anonymizer->characterKey(123456789));
        $this->assertNotSame($character, $anonymizer->participantKey(123456789));
        $this->assertNotSame($character, $anonymizer->nationKey(123456789));
        $this->assertStringNotContainsString('123456789', $character);
        $this->assertMatchesRegularExpression('/^nrc2_[a-f0-9]{32}$/', $character);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $anonymizer->keyId());
    }
}
