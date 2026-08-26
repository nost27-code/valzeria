<?php

namespace App\Services\Nation;

final class NationLevelBenefitSettingsService
{
    public function enabled(): bool
    {
        return (bool) config('features.nation_level_benefits_enabled', false);
    }

    public function assertEnabled(): void
    {
        throw_unless($this->enabled(), \DomainException::class, '国家Lv特典は現在準備中です。');
    }
}
