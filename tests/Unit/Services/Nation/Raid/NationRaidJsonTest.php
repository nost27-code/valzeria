<?php

namespace Tests\Unit\Services\Nation\Raid;

use App\Services\Nation\Raid\NationRaidJson;
use App\Services\Nation\Raid\NationRaidRules;
use App\Services\Nation\Raid\Simulation\NationRaidSimulationProfileCacheHasher;
use PHPUnit\Framework\TestCase;

final class NationRaidJsonTest extends TestCase
{
    public function test_encoding_is_independent_from_and_restores_serialize_precision(): void
    {
        $this->withRestoredSerializePrecision(function (): void {
            $payload = ['rate' => 0.55, 'derived' => 1.15 * 1.5];

            $this->assertNotFalse(ini_set('serialize_precision', '-1'));
            $expected = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            $this->assertNotFalse(ini_set('serialize_precision', '100'));
            $actual = NationRaidJson::encode($payload, JSON_UNESCAPED_SLASHES);

            $this->assertSame($expected, $actual);
            $this->assertSame('100', ini_get('serialize_precision'));
        });
    }

    public function test_ruleset_and_profile_hashes_are_independent_from_serialize_precision(): void
    {
        $this->withRestoredSerializePrecision(function (): void {
            $rules = new NationRaidRules;
            $hasher = new NationRaidSimulationProfileCacheHasher;
            $profile = ['damage' => 123, 'rate' => 0.55, 'multiplier' => 1.15 * 1.5];

            $this->assertNotFalse(ini_set('serialize_precision', '-1'));
            $expectedRulesetHash = $rules->rulesetHash();
            $expectedProfileHash = $hasher->profileHash($profile);

            $this->assertNotFalse(ini_set('serialize_precision', '100'));
            $this->assertSame($expectedRulesetHash, $rules->rulesetHash());
            $this->assertSame($expectedProfileHash, $hasher->profileHash($profile));
            $this->assertSame('100', ini_get('serialize_precision'));
        });
    }

    private function withRestoredSerializePrecision(callable $assertions): void
    {
        $original = ini_get('serialize_precision');
        $this->assertIsString($original);

        try {
            $assertions();
        } finally {
            if (is_string($original)) {
                ini_set('serialize_precision', $original);
            }
        }
    }
}
