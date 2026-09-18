<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\User;
use App\Services\ExplorationStaminaService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExplorationStaminaCampaignTest extends TestCase
{
    use RefreshDatabase;

    public function test_recovery_remains_idempotent_when_a_partial_minute_crosses_the_start(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-19 00:00:00', 'Asia/Tokyo'));

        try {
            $character = Character::query()->create([
                'user_id' => User::factory()->create()->id,
                'name' => '境界時刻テスト冒険者',
                'hp_base' => 100,
                'mp_base' => 100,
                'current_hp' => 100,
                'current_mp' => 100,
                'wins' => 0,
                'explore_stamina' => 100,
                'explore_stamina_max' => 250,
                'explore_stamina_updated_at' => CarbonImmutable::parse('2026-09-18 23:59:01', 'Asia/Tokyo'),
            ]);

            $service = app(ExplorationStaminaService::class);
            $service->recover($character);
            $this->assertSame(101, $character->fresh()->explore_stamina);
            $service->recover($character->fresh());
            $this->assertSame(101, $character->fresh()->explore_stamina);

            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-19 00:00:31', 'Asia/Tokyo'));
            $service->recover($character->fresh());
            $this->assertSame(102, $character->fresh()->explore_stamina);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_recovery_is_saved_across_campaign_start_and_excess_is_kept_after_it_ends(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-19 00:30:00', 'Asia/Tokyo'));

        try {
            $character = Character::query()->create([
                'user_id' => User::factory()->create()->id,
                'name' => '探索力期間テスト冒険者',
                'hp_base' => 100,
                'mp_base' => 100,
                'current_hp' => 100,
                'current_mp' => 100,
                'wins' => 0,
                'explore_stamina' => 0,
                'explore_stamina_max' => 250,
                'explore_stamina_updated_at' => CarbonImmutable::parse('2026-09-18 00:00:00', 'Asia/Tokyo'),
            ]);

            $service = app(ExplorationStaminaService::class);
            $service->recover($character);
            $this->assertSame(290, $character->fresh()->explore_stamina);
            $this->assertSame(750, $character->fresh()->explore_stamina_max);

            $character->update([
                'explore_stamina' => 700,
                'explore_stamina_updated_at' => CarbonImmutable::parse('2026-09-23 23:59:00', 'Asia/Tokyo'),
            ]);
            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-24 00:01:00', 'Asia/Tokyo'));

            $service->recover($character);
            $this->assertSame(701, $character->fresh()->explore_stamina);
            $this->assertSame(250, $character->fresh()->explore_stamina_max);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
