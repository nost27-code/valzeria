<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Services\HeroTrialService;
use DomainException;
use Tests\TestCase;

class HeroTrialFeatureGateTest extends TestCase
{
    public function test_hero_trials_are_closed_by_default(): void
    {
        config(['extra_content.contents.hero_trials.default_enabled' => false]);

        $service = app(HeroTrialService::class);

        $this->assertFalse($service->isEnabled());
        $this->assertSame([], $service->facilitiesFor(new Character, 10));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('英雄試練は現在公開されていません。');

        $service->challenge(new Character, 'dawn_hero');
    }
}
