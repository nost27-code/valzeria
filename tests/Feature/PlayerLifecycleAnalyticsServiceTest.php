<?php

namespace Tests\Feature;

use App\Models\PlayerLifecycleEvent;
use App\Models\User;
use App\Services\Admin\PlayerLifecycleAnalyticsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerLifecycleAnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_dashboard_metrics_include_initial_progress_and_d7_action_comparison(): void
    {
        Carbon::setTestNow('2026-07-23 12:00:00');

        $returnedAfterVictory = User::factory()->create();
        $notReturnedAfterVictory = User::factory()->create();
        $returnedWithoutVictory = User::factory()->create();
        $notReturnedWithoutVictory = User::factory()->create();

        foreach ([$returnedAfterVictory, $notReturnedAfterVictory, $returnedWithoutVictory, $notReturnedWithoutVictory] as $index => $user) {
            $registeredAt = Carbon::parse('2026-07-01 09:00:00')->addMinutes($index);
            $this->record($user, 'registered', 'registered', $registeredAt);

            if (in_array($user->id, [$returnedAfterVictory->id, $notReturnedAfterVictory->id], true)) {
                $this->record($user, 'first_victory', 'first_victory', $registeredAt->copy()->addHour());
            }

            if (in_array($user->id, [$returnedAfterVictory->id, $returnedWithoutVictory->id], true)) {
                $this->record($user, 'login', 'login:2026-07-08', $registeredAt->copy()->addDays(7));
            }
        }

        $todayUser = User::factory()->create();
        $this->record($todayUser, 'registered', 'registered', now()->subHour());
        $this->record($todayUser, 'character_created', 'character_created', now()->subMinutes(50));
        $this->record($todayUser, 'first_battle', 'first_battle', now()->subMinutes(40));
        $this->record($todayUser, 'first_victory', 'first_victory', now()->subMinutes(35));
        $this->record($todayUser, 'first_boss_defeat', 'first_boss_defeat', now()->subMinutes(20));
        $this->record($todayUser, 'city_reached', 'city_reached:2', now()->subMinutes(10), ['city_order' => 2]);

        $metrics = app(PlayerLifecycleAnalyticsService::class)->dashboardMetrics();

        $todayFunnel = collect($metrics['today_funnel'])->keyBy('key');
        $this->assertSame(1, $todayFunnel['character_created']['count']);
        $this->assertSame(1, $todayFunnel['first_boss_defeat']['count']);
        $this->assertSame(1, $todayFunnel['second_city_reached']['count']);

        $victoryInsight = collect($metrics['retention_action_insights'])->firstWhere('label', '初回勝利');
        $this->assertSame(2, $victoryInsight['completed']['eligible']);
        $this->assertSame(1, $victoryInsight['completed']['retained']);
        $this->assertSame(50.0, $victoryInsight['completed']['rate']);
        $this->assertSame(2, $victoryInsight['not_completed']['eligible']);
        $this->assertSame(1, $victoryInsight['not_completed']['retained']);
        $this->assertSame(50.0, $victoryInsight['not_completed']['rate']);
    }

    public function test_admin_dashboard_displays_retention_action_analysis(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('初日行動別 D7再訪')
            ->assertSee('初回ボス撃破')
            ->assertSee('第2都市到達');
    }

    private function record(User $user, string $eventName, string $eventKey, Carbon $occurredAt, array $metadata = []): void
    {
        PlayerLifecycleEvent::query()->create([
            'user_id' => $user->id,
            'event_name' => $eventName,
            'event_key' => $eventKey . ':' . $user->id,
            'metadata' => $metadata ?: null,
            'occurred_at' => $occurredAt,
        ]);
    }
}
