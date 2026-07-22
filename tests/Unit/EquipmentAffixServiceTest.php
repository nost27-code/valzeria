<?php

namespace Tests\Unit;

use App\Services\EquipmentAffixService;
use Tests\TestCase;

class EquipmentAffixServiceTest extends TestCase
{
    public function test_forge_quality_roll_uses_the_configured_good_and_excellent_rates(): void
    {
        $service = app(EquipmentAffixService::class);

        $this->assertSame('excellent', $service->qualityAfterForgeRoll('normal', 1));
        $this->assertSame('good', $service->qualityAfterForgeRoll('normal', 11));
        $this->assertSame('normal', $service->qualityAfterForgeRoll('normal', 111));
    }

    public function test_forge_quality_roll_never_downgrades_and_can_promote_good_to_excellent(): void
    {
        $service = app(EquipmentAffixService::class);

        $this->assertSame('excellent', $service->qualityAfterForgeRoll('good', 1));
        $this->assertSame('good', $service->qualityAfterForgeRoll('good', 11));
        $this->assertSame('excellent', $service->qualityAfterForgeRoll('excellent', 10000));
    }
}
