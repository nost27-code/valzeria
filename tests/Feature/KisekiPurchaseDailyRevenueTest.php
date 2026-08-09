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
            ->assertSee('指定期間売上')
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
            })
            ->assertViewHas('periodRevenueSummary', function (array $summary): bool {
                return $summary['start_date'] === '2026-07-11'
                    && $summary['end_date'] === '2026-08-09'
                    && $summary['period_days'] === 30
                    && $summary['revenue_jpy'] === 8540
                    && $summary['purchase_count'] === 5
                    && $summary['buyer_count'] === 1
                    && $summary['kiseki_amount'] === 992
                    && $summary['average_order_jpy'] === 1708;
            });
    }

    public function test_admin_can_select_a_custom_revenue_period_from_calendar_dates(): void
    {
        Carbon::setTestNow('2026-08-09 12:00:00');

        $admin = User::factory()->create(['role' => 'admin']);
        $firstBuyer = User::factory()->create(['role' => 'user']);
        $secondBuyer = User::factory()->create(['role' => 'user']);
        $firstCharacter = Character::query()->create([
            'user_id' => $firstBuyer->id,
            'name' => '期間集計一人目',
        ]);
        $secondCharacter = Character::query()->create([
            'user_id' => $secondBuyer->id,
            'name' => '期間集計二人目',
        ]);

        $this->createOrder($firstCharacter, 'period-first', 'kiseki_mini', 120, 12, '2026-08-01 00:00:00');
        $this->createOrder($secondCharacter, 'period-second', 'kiseki_standard', 980, 105, '2026-08-03 23:59:59');
        $this->createOrder($firstCharacter, 'period-test', 'kiseki_test', 9999, 9999, '2026-08-02 12:00:00');
        $this->createOrder($firstCharacter, 'period-failed', 'kiseki_large', 4980, 600, '2026-08-02 12:00:00', 'failed');
        $this->createOrder($firstCharacter, 'period-outside', 'kiseki_large', 4980, 600, '2026-07-31 23:59:59');

        Livewire::actingAs($admin)
            ->test(KisekiPurchaseManager::class)
            ->set('revenueStartDate', '2026-08-01')
            ->set('revenueEndDate', '2026-08-03')
            ->call('applyRevenuePeriod')
            ->assertHasNoErrors()
            ->assertSet('appliedRevenueStartDate', '2026-08-01')
            ->assertSet('appliedRevenueEndDate', '2026-08-03')
            ->assertViewHas('periodRevenueSummary', function (array $summary): bool {
                return $summary['period_days'] === 3
                    && $summary['revenue_jpy'] === 1100
                    && $summary['purchase_count'] === 2
                    && $summary['buyer_count'] === 2
                    && $summary['kiseki_amount'] === 117
                    && $summary['average_order_jpy'] === 550;
            });
    }

    public function test_custom_revenue_period_rejects_an_end_date_before_the_start_date(): void
    {
        Carbon::setTestNow('2026-08-09 12:00:00');

        $admin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)
            ->test(KisekiPurchaseManager::class)
            ->set('revenueStartDate', '2026-08-10')
            ->set('revenueEndDate', '2026-08-09')
            ->call('applyRevenuePeriod')
            ->assertHasErrors(['revenueEndDate' => 'after_or_equal'])
            ->assertSet('appliedRevenueStartDate', '2026-07-11')
            ->assertSet('appliedRevenueEndDate', '2026-08-09');
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
