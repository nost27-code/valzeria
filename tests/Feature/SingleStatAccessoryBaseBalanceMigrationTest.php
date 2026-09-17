<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\Item;
use App\Services\CharacterStatusService;
use App\Services\EquipmentEnhancementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SingleStatAccessoryBaseBalanceMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private const FAMILIES = [
        'ACC_POWER_RING' => 'str_bonus',
        'ACC_GUARD_RING' => 'def_bonus',
        'ACC_MAGIC_RING' => 'mag_bonus',
        'ACC_PRAYER_AMULET' => 'spr_bonus',
        'ACC_WIND_CHARM' => 'agi_bonus',
        'ACC_LUCK_CHARM' => 'luk_bonus',
        'ACC_BR_POWER' => 'str_bonus',
        'ACC_BR_MAGIC' => 'mag_bonus',
        'ACC_BR_PRAYER' => 'spr_bonus',
        'ACC_BR_WIND' => 'agi_bonus',
        'ACC_BR_LUCK' => 'luk_bonus',
    ];

    /** @var array<string, array{old: int, new: int}> */
    private const RANK_VALUES = [
        'S' => ['old' => 192, 'new' => 208],
        'SS' => ['old' => 264, 'new' => 288],
        'SSS' => ['old' => 352, 'new' => 384],
        'EPIC' => ['old' => 480, 'new' => 520],
    ];

    public function test_all_44_single_stat_items_gain_only_the_approved_base_amount(): void
    {
        $this->assertExpectedValues('new');

        $this->assertSame(144, (int) DB::table('items')->where('external_item_id', 'ACC_POWER_RING_A')->value('str_bonus'));
        $this->assertSame(0, (int) DB::table('items')->where('external_item_id', 'ACC_WIND_CHARM_SSS')->value('luk_bonus'));
        $this->assertSame(324, (int) DB::table('items')->where('external_item_id', 'ACC_BR_GUARD_S')->value('hp_bonus'));
        $this->assertSame(160, (int) DB::table('items')->where('external_item_id', 'ACC_BR_GUARD_S')->value('def_bonus'));
        $this->assertSame(64, (int) DB::table('items')->where('external_item_id', 'ACC_BALANCE_BRACELET_S')->value('str_bonus'));
        $this->assertSame(980, (int) DB::table('items')->where('external_item_id', 'ACC_LIFE_NECKLACE_S')->value('hp_bonus'));
        $this->assertSame(976, (int) DB::table('items')->where('external_item_id', 'ACC_MIND_EARRING_S')->value('mp_bonus'));
    }

    public function test_enhancement_uses_new_master_without_changing_growth_or_evolution_unlock(): void
    {
        $s = Item::query()->where('external_item_id', 'ACC_POWER_RING_S')->firstOrFail();
        $ss = Item::query()->where('external_item_id', 'ACC_POWER_RING_SS')->firstOrFail();

        $this->assertSame(['str' => 208], EquipmentEnhancementService::enhancedStatTotalsForItem($s, 0));
        $this->assertSame(['str' => 448], EquipmentEnhancementService::enhancedStatTotalsForItem($s, 25));
        $this->assertSame(['str' => 288], EquipmentEnhancementService::enhancedStatTotalsForItem($ss, 0));
        $this->assertSame(['str' => 568], EquipmentEnhancementService::enhancedStatTotalsForItem($ss, 30));
        $this->assertSame(
            568,
            app(CharacterStatusService::class)->equipmentStatsForItem(new Character, $ss, 30)['str']
        );

        $windSss = Item::query()->where('external_item_id', 'ACC_WIND_CHARM_SSS')->firstOrFail();
        $this->assertSame(['agi' => 656], EquipmentEnhancementService::enhancedStatTotalsForItem($windSss, 30));

        $recipe = DB::table('accessory_evolution_recipes')
            ->where('from_accessory_id', 'ACC_POWER_RING_S')
            ->first(['requires_hidden_dungeon_unlocked', 'is_active']);
        $this->assertNotNull($recipe);
        $this->assertSame(1, (int) $recipe->requires_hidden_dungeon_unlocked);
        $this->assertSame(1, (int) $recipe->is_active);
    }

    public function test_migration_is_idempotent_and_can_restore_previous_values(): void
    {
        $migration = $this->migration();
        $newSnapshot = $this->snapshot();

        $migration->up();
        $this->assertSame($newSnapshot, $this->snapshot());

        $migration->down();
        $this->assertExpectedValues('old');
        $migration->down();
        $this->assertExpectedValues('old');

        $migration->up();
        $this->assertSame($newSnapshot, $this->snapshot());
    }

    public function test_unknown_master_value_aborts_without_partial_changes(): void
    {
        $migration = $this->migration();
        $migration->down();
        DB::table('items')->where('external_item_id', 'ACC_BR_LUCK_EPIC')->update(['luk_bonus' => 999]);
        $snapshot = $this->snapshot();

        try {
            $migration->up();
            $this->fail('Unknown master value was accepted.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('ACC_BR_LUCK_EPIC', $exception->getMessage());
        }

        $this->assertSame($snapshot, $this->snapshot());
    }

    private function assertExpectedValues(string $version): void
    {
        $checked = 0;
        foreach (self::FAMILIES as $prefix => $stat) {
            foreach (self::RANK_VALUES as $rank => $values) {
                $externalId = "{$prefix}_{$rank}";
                $expected = $externalId === 'ACC_WIND_CHARM_SSS'
                    ? ['old' => 344, 'new' => 376][$version]
                    : $values[$version];
                $row = DB::table('items')->where('external_item_id', $externalId)->first();

                $this->assertNotNull($row, $externalId);
                $this->assertSame('accessory', $row->type, $externalId);
                $this->assertSame($rank, $row->accessory_rank, $externalId);
                $this->assertSame(0, (int) $row->hp_bonus, $externalId);
                $this->assertSame(0, (int) $row->mp_bonus, $externalId);
                $this->assertSame($expected, (int) $row->{$stat}, $externalId);
                $checked++;
            }
        }
        $this->assertSame(44, $checked);
    }

    /** @return list<string> */
    private function snapshot(): array
    {
        return DB::table('items')
            ->where('type', 'accessory')
            ->whereIn('accessory_rank', array_keys(self::RANK_VALUES))
            ->orderBy('external_item_id')
            ->get(['external_item_id', 'hp_bonus', 'mp_bonus', 'str_bonus', 'def_bonus', 'agi_bonus', 'mag_bonus', 'spr_bonus', 'luk_bonus'])
            ->map(fn (object $row): string => implode(':', (array) $row))
            ->all();
    }

    private function migration(): object
    {
        return require base_path('database/migrations/2026_09_17_010000_raise_single_stat_accessory_base_values.php');
    }
}
