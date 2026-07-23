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

    public function test_dashboard_metrics_separate_initial_progress_and_measure_milestone_time(): void
    {
        Carbon::setTestNow('2026-07-23 12:00:00');

        $returnedAfterCityUnlock = User::factory()->create();
        $notReturnedAfterBossDefeat = User::factory()->create();
        $returnedAfterEquipmentChange = User::factory()->create();
        $notReturnedBeforeVictory = User::factory()->create();

        foreach ([$returnedAfterCityUnlock, $notReturnedAfterBossDefeat, $returnedAfterEquipmentChange, $notReturnedBeforeVictory] as $index => $user) {
            $registeredAt = Carbon::parse('2026-07-01 09:00:00')->addMinutes($index);
            $user->forceFill(['created_at' => $registeredAt])->save();
            $this->record($user, 'registered', 'registered', $registeredAt);

            if ($user->id !== $notReturnedBeforeVictory->id) {
                $this->record($user, 'first_victory', 'first_victory', $registeredAt->copy()->addMinutes(20));
            }

            if (in_array($user->id, [$returnedAfterCityUnlock->id, $notReturnedAfterBossDefeat->id, $returnedAfterEquipmentChange->id], true)) {
                $this->record($user, 'first_equipment_change', 'first_equipment_change', $registeredAt->copy()->addMinutes(30));
            }

            if (in_array($user->id, [$returnedAfterCityUnlock->id, $notReturnedAfterBossDefeat->id], true)) {
                $this->record($user, 'first_boss_defeat', 'first_boss_defeat', $registeredAt->copy()->addMinutes(50));
            }

            if ($user->id === $returnedAfterCityUnlock->id) {
                $this->record($user, 'first_next_city_unlocked', 'first_next_city_unlocked', $registeredAt->copy()->addMinutes(60));
            }

            if (in_array($user->id, [$returnedAfterCityUnlock->id, $returnedAfterEquipmentChange->id], true)) {
                $this->record($user, 'login', 'login:2026-07-08', $registeredAt->copy()->addDays(7));
            }
        }

        $todayUser = User::factory()->create();
        $this->record($todayUser, 'registered', 'registered', now()->subHour());
        $this->record($todayUser, 'character_created', 'character_created', now()->subMinutes(50));
        $this->record($todayUser, 'first_battle', 'first_battle', now()->subMinutes(40));
        $this->record($todayUser, 'first_victory', 'first_victory', now()->subMinutes(35));
        $this->record($todayUser, 'first_boss_defeat', 'first_boss_defeat', now()->subMinutes(20));
        $this->record($todayUser, 'first_next_city_unlocked', 'first_next_city_unlocked', now()->subMinutes(10));

        $metrics = app(PlayerLifecycleAnalyticsService::class)->dashboardMetrics();

        $todayFunnel = collect($metrics['today_funnel'])->keyBy('key');
        $this->assertSame(1, $todayFunnel['character_created']['count']);
        $this->assertSame(1, $todayFunnel['first_boss_defeat']['count']);
        $this->assertSame(1, $todayFunnel['first_next_city_unlocked']['count']);

        $progress = collect($metrics['initial_progress_d7'])->keyBy('key');
        $this->assertSame(1, $progress['first_next_city_unlocked']['eligible']);
        $this->assertSame(1, $progress['first_next_city_unlocked']['retained']);
        $this->assertSame(100.0, $progress['first_next_city_unlocked']['rate']);
        $this->assertSame(1, $progress['first_boss_defeat']['eligible']);
        $this->assertSame(0, $progress['first_boss_defeat']['retained']);
        $this->assertSame(1, $progress['first_equipment_change']['eligible']);
        $this->assertSame(1, $progress['before_first_victory']['eligible']);

        $bossTime = collect($metrics['initial_milestone_times'])->firstWhere('label', '初回ボス撃破');
        $this->assertSame(2, $bossTime['count']);
        $this->assertSame(50, $bossTime['median_minutes']);
    }

    public function test_admin_dashboard_displays_initial_progress_retention_analysis(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('初日進行別 D7再訪')
            ->assertSee('初回ボス撃破')
            ->assertSee('初めて次の街を解放')
            ->assertSee('初日到達までの所要時間');
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
