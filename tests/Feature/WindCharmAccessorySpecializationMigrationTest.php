<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Services\EquipmentEnhancementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class WindCharmAccessorySpecializationMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, int> */
    private const EXPECTED_AGILITY_TOTALS = [
        'ACC_WIND_CHARM_G' => 24,
        'ACC_WIND_CHARM_F' => 24,
        'ACC_WIND_CHARM_E' => 32,
        'ACC_WIND_CHARM_D' => 48,
        'ACC_WIND_CHARM_C' => 72,
        'ACC_WIND_CHARM_B' => 96,
        'ACC_WIND_CHARM_A' => 144,
        'ACC_WIND_CHARM_S' => 192,
        'ACC_WIND_CHARM_SS' => 264,
        'ACC_WIND_CHARM_SSS' => 344,
        'ACC_WIND_CHARM_EPIC' => 480,
    ];

    public function test_wind_charm_family_moves_its_existing_total_to_agility_only(): void
    {
        $items = DB::table('items')
            ->where('accessory_family_id', 'WIND_CHARM')
            ->get(['external_item_id', 'agi_bonus', 'luk_bonus', 'description'])
            ->keyBy('external_item_id');

        $this->assertCount(count(self::EXPECTED_AGILITY_TOTALS), $items);

        foreach (self::EXPECTED_AGILITY_TOTALS as $externalItemId => $expectedAgility) {
            $item = $items->get($externalItemId);

            $this->assertNotNull($item, $externalItemId);
            $this->assertSame($expectedAgility, (int) $item->agi_bonus, $externalItemId);
            $this->assertSame(0, (int) $item->luk_bonus, $externalItemId);
            $this->assertSame('敏捷を補助する装飾品。', $item->description, $externalItemId);
        }

        $family = DB::table('accessory_families')
            ->where('accessory_family_id', 'WIND_CHARM')
            ->first(['base_spd', 'base_luk', 'role_description']);

        $this->assertNotNull($family);
        $this->assertSame(3, (int) $family->base_spd);
        $this->assertSame(0, (int) $family->base_luk);
        $this->assertSame('敏捷を補助する装飾品。', $family->role_description);

        $sRank = Item::query()->where('external_item_id', 'ACC_WIND_CHARM_S')->firstOrFail();
        $ssRank = Item::query()->where('external_item_id', 'ACC_WIND_CHARM_SS')->firstOrFail();

        $this->assertSame(
            ['agi' => 392],
            EquipmentEnhancementService::enhancedStatTotalsForItem($sRank, 25)
        );
        $this->assertSame(
            ['agi' => 1600],
            EquipmentEnhancementService::enhancedStatTotalsForItem($ssRank, 30)
        );

        $luckCharm = DB::table('items')
            ->where('external_item_id', 'ACC_LUCK_CHARM_S')
            ->first(['agi_bonus', 'luk_bonus']);

        $this->assertNotNull($luckCharm);
        $this->assertSame(0, (int) $luckCharm->agi_bonus);
        $this->assertSame(192, (int) $luckCharm->luk_bonus);
    }

    public function test_migration_is_idempotent_and_rolls_back_to_the_exact_previous_distribution(): void
    {
        $migration = $this->migration();
        $specializedSnapshot = $this->windCharmSnapshot();
        $unrelatedLuckCharm = DB::table('items')
            ->where('external_item_id', 'ACC_LUCK_CHARM_S')
            ->first(['agi_bonus', 'luk_bonus', 'description']);

        $migration->up();
        $this->assertSame($specializedSnapshot, $this->windCharmSnapshot());

        $migration->down();

        foreach ($this->oldStatDistribution() as $externalItemId => $expected) {
            $row = DB::table('items')
                ->where('external_item_id', $externalItemId)
                ->first(['agi_bonus', 'luk_bonus', 'description']);

            $this->assertNotNull($row, $externalItemId);
            $this->assertSame($expected['agi'], (int) $row->agi_bonus, $externalItemId);
            $this->assertSame($expected['luk'], (int) $row->luk_bonus, $externalItemId);
            $this->assertSame(
                self::EXPECTED_AGILITY_TOTALS[$externalItemId],
                (int) $row->agi_bonus + (int) $row->luk_bonus,
                $externalItemId
            );
            $this->assertSame('速度と運を補助する装飾品。', $row->description, $externalItemId);
        }

        $migration->down();
        $migration->up();

        $this->assertSame($specializedSnapshot, $this->windCharmSnapshot());
        $this->assertEquals(
            $unrelatedLuckCharm,
            DB::table('items')
                ->where('external_item_id', 'ACC_LUCK_CHARM_S')
                ->first(['agi_bonus', 'luk_bonus', 'description'])
        );
    }

    public function test_every_rank_and_enhancement_level_keeps_the_previous_positive_stat_total(): void
    {
        $service = app(EquipmentEnhancementService::class);

        foreach ($this->oldStatDistribution() as $externalItemId => $oldStats) {
            $specializedItem = Item::query()->where('external_item_id', $externalItemId)->firstOrFail();
            $previousItem = (object) [
                'type' => 'accessory',
                'accessory_rank' => $specializedItem->accessory_rank,
                'accessory_performance_scale_version' => 2,
                'agi_bonus' => $oldStats['agi'],
                'luk_bonus' => $oldStats['luk'],
            ];

            foreach (range(0, $service->maxEnhanceFor($specializedItem)) as $enhanceLevel) {
                $previousTotals = EquipmentEnhancementService::enhancedStatTotalsForItem($previousItem, $enhanceLevel);
                $specializedTotals = EquipmentEnhancementService::enhancedStatTotalsForItem($specializedItem, $enhanceLevel);
                $message = "{$externalItemId} +{$enhanceLevel}";

                $this->assertSame(array_sum($previousTotals), array_sum($specializedTotals), $message);
                $this->assertSame(['agi' => array_sum($specializedTotals)], $specializedTotals, $message);
            }
        }
    }

    public function test_migration_rejects_an_unknown_master_value_before_changing_any_row(): void
    {
        $migration = $this->migration();
        $migration->down();

        DB::table('items')
            ->where('external_item_id', 'ACC_WIND_CHARM_EPIC')
            ->update(['luk_bonus' => 999]);

        $snapshotBefore = $this->windCharmSnapshot();

        try {
            $migration->up();
            $this->fail('The migration accepted an unknown WIND_CHARM master value.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('ACC_WIND_CHARM_EPIC', $exception->getMessage());
        }

        $this->assertSame($snapshotBefore, $this->windCharmSnapshot());
    }

    /** @return array<string, array{agi: int, luk: int}> */
    private function oldStatDistribution(): array
    {
        return [
            'ACC_WIND_CHARM_G' => ['agi' => 16, 'luk' => 8],
            'ACC_WIND_CHARM_F' => ['agi' => 16, 'luk' => 8],
            'ACC_WIND_CHARM_E' => ['agi' => 24, 'luk' => 8],
            'ACC_WIND_CHARM_D' => ['agi' => 32, 'luk' => 16],
            'ACC_WIND_CHARM_C' => ['agi' => 48, 'luk' => 24],
            'ACC_WIND_CHARM_B' => ['agi' => 64, 'luk' => 32],
            'ACC_WIND_CHARM_A' => ['agi' => 96, 'luk' => 48],
            'ACC_WIND_CHARM_S' => ['agi' => 128, 'luk' => 64],
            'ACC_WIND_CHARM_SS' => ['agi' => 176, 'luk' => 88],
            'ACC_WIND_CHARM_SSS' => ['agi' => 232, 'luk' => 112],
            'ACC_WIND_CHARM_EPIC' => ['agi' => 320, 'luk' => 160],
        ];
    }

    /** @return list<string> */
    private function windCharmSnapshot(): array
    {
        return DB::table('items')
            ->where('accessory_family_id', 'WIND_CHARM')
            ->orderBy('external_item_id')
            ->get(['external_item_id', 'name', 'agi_bonus', 'luk_bonus', 'description'])
            ->map(fn (object $row): string => implode(':', [
                $row->external_item_id,
                $row->name,
                $row->agi_bonus,
                $row->luk_bonus,
                $row->description,
            ]))
            ->all();
    }

    private function migration(): object
    {
        return require base_path('database/migrations/2026_08_31_030000_specialize_wind_charm_accessories_for_agility.php');
    }
}
