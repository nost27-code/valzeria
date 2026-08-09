<?php

namespace Tests\Feature;

use App\Livewire\Admin\KisekiPurchaseManager;
use App\Models\Character;
use App\Models\StripeOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class KisekiPurchaseDailyRevenueTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_admin_can_see_zero_filled_daily_revenue_and_period_totals(): void
    {
        Carbon::setTestNow('2026-08-09 12:00:00');

        $admin = User::factory()->create(['role' => 'admin']);
        $buyer = User::factory()->create(['role' => 'user']);
        $character = Character::query()->create([
            'user_id' => $buyer->id,
            'name' => '日次売上確認',
        ]);

        $this->createOrder($character, 'today-mini', 'kiseki_mini', 120, 12, '2026-08-09 09:00:00');
        $this->createOrder($character, 'today-light', 'kiseki_light', 480, 50, '2026-08-09 10:00:00');
        $this->createOrder($character, 'yesterday-standard', 'kiseki_standard', 980, 105, '2026-08-08 18:00:00');
        $this->createOrder($character, 'week-value', 'kiseki_value', 1980, 225, '2026-08-03 12:00:00');
        $this->createOrder($character, 'month-large', 'kiseki_large', 4980, 600, '2026-07-11 12:00:00');

        $component = Livewire::actingAs($admin)->test(KisekiPurchaseManager::class);

        $component
            ->assertSee('日別売上（直近30日）')
            ->assertSee('直近7日売上')
            ->assertSee('直近30日売上')
            ->assertViewHas('dailyRevenueSummary', function (array $summary): bool {
                $days = collect($summary['daily'])->keyBy('date');

                return count($summary['daily']) === 30
                    && $summary['today']['revenue_jpy'] === 600
                    && $summary['today']['purchase_count'] === 2
                    && $summary['today']['buyer_count'] === 1
                    && $summary['yesterday']['revenue_jpy'] === 980
                    && $summary['last_7_days']['revenue_jpy'] === 3560
                    && $summary['last_7_days']['purchase_count'] === 4
                    && $summary['last_30_days']['revenue_jpy'] === 8540
                    && $days['2026-08-07']['revenue_jpy'] === 0
                    && $days['2026-07-11']['revenue_jpy'] === 4980;
            });
    }

    public function test_daily_revenue_excludes_test_and_unfulfilled_orders_and_uses_fulfilled_date(): void
    {
        Carbon::setTestNow('2026-08-09 12:00:00');

        $admin = User::factory()->create(['role' => 'admin']);
        $buyer = User::factory()->create(['role' => 'user']);
        $character = Character::query()->create([
            'user_id' => $buyer->id,
            'name' => '集計条件確認',
        ]);

        $this->createOrder($character, 'live-order', 'kiseki_standard', 980, 105, '2026-08-08 23:30:00');
        $this->createOrder($character, 'test-order', 'kiseki_test', 9999, 9999, '2026-08-09 10:00:00');
        $this->createOrder($character, 'failed-order', 'kiseki_large', 4980, 600, null, 'failed');

        Livewire::actingAs($admin)
            ->test(KisekiPurchaseManager::class)
            ->assertViewHas('dailyRevenueSummary', function (array $summary): bool {
                $days = collect($summary['daily'])->keyBy('date');

                return $summary['today']['revenue_jpy'] === 0
                    && $summary['yesterday']['revenue_jpy'] === 980
                    && $summary['last_30_days']['revenue_jpy'] === 980
                    && $days['2026-08-08']['purchase_count'] === 1;
            });
    }

    public function test_kiseki_purchase_audit_route_remains_admin_only(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user)
            ->get(route('admin.kiseki-purchases'))
            ->assertRedirect('/admin/login');

        Livewire::actingAs($user)
            ->test(KisekiPurchaseManager::class)
            ->assertForbidden();
    }

    private function createOrder(
        Character $character,
        string $sessionId,
        string $packKey,
        int $priceJpy,
        int $kisekiAmount,
        ?string $fulfilledAt,
        string $status = 'fulfilled',
    ): StripeOrder {
        return StripeOrder::query()->create([
            'session_id' => $sessionId,
            'character_id' => $character->id,
            'pack_key' => $packKey,
            'price_jpy' => $priceJpy,
            'kiseki_amount' => $kisekiAmount,
            'status' => $status,
            'fulfilled_at' => $fulfilledAt,
        ]);
    }
}
