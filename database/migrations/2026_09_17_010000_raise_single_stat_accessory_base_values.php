<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, array{family: string, stat: string}> */
    private const FAMILIES = [
        'ACC_POWER_RING' => ['family' => 'POWER_RING', 'stat' => 'str_bonus'],
        'ACC_GUARD_RING' => ['family' => 'GUARD_RING', 'stat' => 'def_bonus'],
        'ACC_MAGIC_RING' => ['family' => 'MAGIC_RING', 'stat' => 'mag_bonus'],
        'ACC_PRAYER_AMULET' => ['family' => 'PRAYER_AMULET', 'stat' => 'spr_bonus'],
        'ACC_WIND_CHARM' => ['family' => 'WIND_CHARM', 'stat' => 'agi_bonus'],
        'ACC_LUCK_CHARM' => ['family' => 'LUCK_CHARM', 'stat' => 'luk_bonus'],
        'ACC_BR_POWER' => ['family' => 'BR_POWER_ACCESSORY', 'stat' => 'str_bonus'],
        // BR_GUARD has HP as well as defense and is intentionally excluded.
        'ACC_BR_MAGIC' => ['family' => 'BR_MAGIC_ACCESSORY', 'stat' => 'mag_bonus'],
        'ACC_BR_PRAYER' => ['family' => 'BR_PRAYER_ACCESSORY', 'stat' => 'spr_bonus'],
        'ACC_BR_WIND' => ['family' => 'BR_WIND_ACCESSORY', 'stat' => 'agi_bonus'],
        'ACC_BR_LUCK' => ['family' => 'BR_LUCK_ACCESSORY', 'stat' => 'luk_bonus'],
    ];

    /** @var array<string, array{old: int, new: int}> */
    private const RANK_VALUES = [
        'S' => ['old' => 192, 'new' => 208],
        'SS' => ['old' => 264, 'new' => 288],
        'SSS' => ['old' => 352, 'new' => 384],
        'EPIC' => ['old' => 480, 'new' => 520],
    ];

    public function up(): void
    {
        $this->apply('new');
    }

    public function down(): void
    {
        $this->apply('old');
    }

    private function apply(string $target): void
    {
        if (! Schema::hasTable('items')) {
            throw new RuntimeException('Required table [items] is missing.');
        }

        foreach (['id', 'external_item_id', 'type', 'accessory_family_id', 'accessory_rank',
            'accessory_performance_scale_version', 'hp_bonus', 'mp_bonus', 'str_bonus',
            'def_bonus', 'agi_bonus', 'mag_bonus', 'spr_bonus', 'luk_bonus', 'updated_at'] as $column) {
            if (! Schema::hasColumn('items', $column)) {
                throw new RuntimeException("Required column [items.{$column}] is missing.");
            }
        }

        DB::transaction(function () use ($target): void {
            $expected = [];
            foreach (self::FAMILIES as $prefix => $definition) {
                foreach (self::RANK_VALUES as $rank => $values) {
                    $externalId = "{$prefix}_{$rank}";
                    $expected[$externalId] = $definition + ['rank' => $rank, 'values' => $values];
                }
            }

            $rows = DB::table('items')
                ->whereIn('external_item_id', array_keys($expected))
                ->lockForUpdate()
                ->get()
                ->keyBy('external_item_id');

            if ($rows->count() !== count($expected)) {
                throw new RuntimeException('Single-stat accessory master is incomplete. No rows were changed.');
            }

            foreach ($expected as $externalId => $definition) {
                $row = $rows->get($externalId);
                $stat = $definition['stat'];
                $values = $definition['values'];
                if ($externalId === 'ACC_WIND_CHARM_SSS') {
                    $values = ['old' => 344, 'new' => 376];
                }

                $otherStatsAreZero = true;
                foreach (['str_bonus', 'def_bonus', 'agi_bonus', 'mag_bonus', 'spr_bonus', 'luk_bonus'] as $column) {
                    if ($column !== $stat && (int) $row->{$column} !== 0) {
                        $otherStatsAreZero = false;
                    }
                }

                if ($row->type !== 'accessory'
                    || $row->accessory_family_id !== $definition['family']
                    || $row->accessory_rank !== $definition['rank']
                    || (int) $row->accessory_performance_scale_version !== 2
                    || (int) $row->hp_bonus !== 0
                    || (int) $row->mp_bonus !== 0
                    || ! $otherStatsAreZero
                    || ! in_array((int) $row->{$stat}, $values, true)) {
                    throw new RuntimeException("Unexpected single-stat accessory master state for [{$externalId}]. No rows were changed.");
                }
            }

            $now = now();
            foreach ($expected as $externalId => $definition) {
                $row = $rows->get($externalId);
                $values = $externalId === 'ACC_WIND_CHARM_SSS'
                    ? ['old' => 344, 'new' => 376]
                    : $definition['values'];
                $stat = $definition['stat'];
                if ((int) $row->{$stat} === $values[$target]) {
                    continue;
                }

                DB::table('items')->where('id', $row->id)->update([
                    $stat => $values[$target],
                    'updated_at' => $now,
                ]);
            }
        });
    }
};
