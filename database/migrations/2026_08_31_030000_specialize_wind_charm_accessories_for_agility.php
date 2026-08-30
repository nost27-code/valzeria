<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FAMILY_ID = 'WIND_CHARM';

    private const OLD_DESCRIPTION = '速度と運を補助する装飾品。';

    private const NEW_DESCRIPTION = '敏捷を補助する装飾品。';

    /**
     * @var array<string, array{name: string, old_agi: int, old_luk: int, new_agi: int}>
     */
    private const ITEMS = [
        'ACC_WIND_CHARM_G' => ['name' => '風の羽飾り', 'old_agi' => 16, 'old_luk' => 8, 'new_agi' => 24],
        'ACC_WIND_CHARM_F' => ['name' => '疾風の羽飾り', 'old_agi' => 16, 'old_luk' => 8, 'new_agi' => 24],
        'ACC_WIND_CHARM_E' => ['name' => '早駆けの羽飾り', 'old_agi' => 24, 'old_luk' => 8, 'new_agi' => 32],
        'ACC_WIND_CHARM_D' => ['name' => '風読みの羽飾り', 'old_agi' => 32, 'old_luk' => 16, 'new_agi' => 48],
        'ACC_WIND_CHARM_C' => ['name' => '影走りの羽飾り', 'old_agi' => 48, 'old_luk' => 24, 'new_agi' => 72],
        'ACC_WIND_CHARM_B' => ['name' => '迅雷の羽飾り', 'old_agi' => 64, 'old_luk' => 32, 'new_agi' => 96],
        'ACC_WIND_CHARM_A' => ['name' => '神速の羽飾り', 'old_agi' => 96, 'old_luk' => 48, 'new_agi' => 144],
        'ACC_WIND_CHARM_S' => ['name' => '天翔の羽飾り', 'old_agi' => 128, 'old_luk' => 64, 'new_agi' => 192],
        'ACC_WIND_CHARM_SS' => ['name' => '星渡りの羽飾り', 'old_agi' => 176, 'old_luk' => 88, 'new_agi' => 264],
        'ACC_WIND_CHARM_SSS' => ['name' => '時渡りの羽飾り', 'old_agi' => 232, 'old_luk' => 112, 'new_agi' => 344],
        'ACC_WIND_CHARM_EPIC' => ['name' => '時空を越える翼飾り', 'old_agi' => 320, 'old_luk' => 160, 'new_agi' => 480],
    ];

    public function up(): void
    {
        $this->assertRequiredSchema();

        DB::transaction(function (): void {
            $this->assertKnownCurrentState();

            $now = now();
            foreach (self::ITEMS as $externalItemId => $item) {
                DB::table('items')
                    ->where('external_item_id', $externalItemId)
                    ->where('accessory_family_id', self::FAMILY_ID)
                    ->update([
                        'agi_bonus' => $item['new_agi'],
                        'luk_bonus' => 0,
                        'description' => self::NEW_DESCRIPTION,
                        'updated_at' => $now,
                    ]);
            }

            DB::table('accessory_families')
                ->where('accessory_family_id', self::FAMILY_ID)
                ->update([
                    'base_spd' => 3,
                    'base_luk' => 0,
                    'role_description' => self::NEW_DESCRIPTION,
                    'updated_at' => $now,
                ]);
        });
    }

    public function down(): void
    {
        $this->assertRequiredSchema();

        DB::transaction(function (): void {
            $this->assertKnownCurrentState();

            $now = now();
            foreach (self::ITEMS as $externalItemId => $item) {
                DB::table('items')
                    ->where('external_item_id', $externalItemId)
                    ->where('accessory_family_id', self::FAMILY_ID)
                    ->update([
                        'agi_bonus' => $item['old_agi'],
                        'luk_bonus' => $item['old_luk'],
                        'description' => self::OLD_DESCRIPTION,
                        'updated_at' => $now,
                    ]);
            }

            DB::table('accessory_families')
                ->where('accessory_family_id', self::FAMILY_ID)
                ->update([
                    'base_spd' => 2,
                    'base_luk' => 1,
                    'role_description' => self::OLD_DESCRIPTION,
                    'updated_at' => $now,
                ]);
        });
    }

    private function assertRequiredSchema(): void
    {
        foreach (['items', 'accessory_families'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Required table [{$table}] is missing.");
            }
        }

        foreach (['external_item_id', 'name', 'accessory_family_id', 'agi_bonus', 'luk_bonus', 'description'] as $column) {
            if (! Schema::hasColumn('items', $column)) {
                throw new RuntimeException("Required column [items.{$column}] is missing.");
            }
        }

        foreach (['accessory_family_id', 'base_spd', 'base_luk', 'role_description'] as $column) {
            if (! Schema::hasColumn('accessory_families', $column)) {
                throw new RuntimeException("Required column [accessory_families.{$column}] is missing.");
            }
        }
    }

    private function assertKnownCurrentState(): void
    {
        $rows = DB::table('items')
            ->whereIn('external_item_id', array_keys(self::ITEMS))
            ->get(['external_item_id', 'name', 'accessory_family_id', 'agi_bonus', 'luk_bonus', 'description'])
            ->keyBy('external_item_id');

        if ($rows->count() !== count(self::ITEMS)) {
            throw new RuntimeException('The WIND_CHARM accessory master is incomplete. No rows were changed.');
        }

        foreach (self::ITEMS as $externalItemId => $expected) {
            $row = $rows->get($externalItemId);
            $hasExpectedIdentity = $row !== null
                && $row->name === $expected['name']
                && $row->accessory_family_id === self::FAMILY_ID;
            $hasOldStats = $row !== null
                && (int) $row->agi_bonus === $expected['old_agi']
                && (int) $row->luk_bonus === $expected['old_luk'];
            $hasNewStats = $row !== null
                && (int) $row->agi_bonus === $expected['new_agi']
                && (int) $row->luk_bonus === 0;
            $hasKnownDescription = $row !== null
                && in_array($row->description, [self::OLD_DESCRIPTION, self::NEW_DESCRIPTION], true);

            if (! $hasExpectedIdentity || (! $hasOldStats && ! $hasNewStats) || ! $hasKnownDescription) {
                throw new RuntimeException("Unexpected WIND_CHARM master state for [{$externalItemId}]. No rows were changed.");
            }
        }

        $family = DB::table('accessory_families')
            ->where('accessory_family_id', self::FAMILY_ID)
            ->first(['base_spd', 'base_luk', 'role_description']);
        $hasOldFamilyStats = $family !== null
            && (int) $family->base_spd === 2
            && (int) $family->base_luk === 1;
        $hasNewFamilyStats = $family !== null
            && (int) $family->base_spd === 3
            && (int) $family->base_luk === 0;
        $hasKnownFamilyDescription = $family !== null
            && in_array($family->role_description, [self::OLD_DESCRIPTION, self::NEW_DESCRIPTION], true);

        if ((! $hasOldFamilyStats && ! $hasNewFamilyStats) || ! $hasKnownFamilyDescription) {
            throw new RuntimeException('Unexpected WIND_CHARM family master state. No rows were changed.');
        }
    }
};
