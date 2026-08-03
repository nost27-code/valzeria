<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\CharacterItemDailySupply;
use App\Models\Item;
use App\Models\User;
use App\Services\DailySupplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DailySupplyStatusPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_batches_three_supply_items_without_changing_counts(): void
    {
        $character = Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => '補給一括集計テスト',
            'current_city_id' => DB::table('cities')->value('id'),
            'highest_city_id' => DB::table('cities')->value('id'),
            'current_job_id' => DB::table('job_classes')->value('id'),
        ]);
        $herb = Item::query()->where('type', 'consumable')->where('name', '薬草')->firstOrFail();

        foreach (range(1, 4) as $_) {
            CharacterItem::query()->create([
                'character_id' => $character->id,
                'item_id' => $herb->id,
                'is_equipped' => false,
            ]);
        }

        CharacterItemDailySupply::query()->create([
            'character_id' => $character->id,
            'item_id' => $herb->id,
            'claimed_on' => now()->subDay()->toDateString(),
            'supplied_count' => 6,
            'stocked_count' => 4,
        ]);
        CharacterItemDailySupply::query()->create([
            'character_id' => $character->id,
            'item_id' => $herb->id,
            'claimed_on' => now()->toDateString(),
            'supplied_count' => 2,
            'stocked_count' => 3,
        ]);

        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, '"items"')
                || str_contains($query->sql, '"character_items"')
                || str_contains($query->sql, '"character_item_daily_supplies"')) {
                $queries++;
            }
        });

        $statuses = app(DailySupplyService::class)->statusFor($character);
        $herbStatus = collect($statuses)->firstWhere('name', '薬草');

        $this->assertSame(3, $queries);
        $this->assertSame(4, $herbStatus['owned_count']);
        $this->assertSame(7, $herbStatus['stocked_count']);
        $this->assertSame(5, $herbStatus['daily_remaining']);
        $this->assertSame(6, $herbStatus['claimable_count']);
        $this->assertSame(12, $herbStatus['depot_count']);
        $this->assertSame(4, $herbStatus['carried_stock_count']);
        $this->assertSame(2, $herbStatus['supplied_count']);
        $this->assertSame(3, $herbStatus['stocked_today']);
        $this->assertFalse($herbStatus['claimed_today']);
    }
}
