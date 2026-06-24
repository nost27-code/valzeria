<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $requiredTables = ['areas', 'battle_logs', 'character_items', 'items'];
        foreach ($requiredTables as $table) {
            if (!Schema::hasTable($table)) {
                return;
            }
        }

        if (
            !Schema::hasColumn('areas', 'is_route_area')
            || !Schema::hasColumn('battle_logs', 'dropped_character_item_id')
        ) {
            return;
        }

        $invalidRows = DB::table('battle_logs')
            ->join('areas', 'battle_logs.area_id', '=', 'areas.id')
            ->join('character_items', 'battle_logs.dropped_character_item_id', '=', 'character_items.id')
            ->join('items', 'character_items.item_id', '=', 'items.id')
            ->where('areas.is_route_area', true)
            ->whereNotNull('battle_logs.dropped_character_item_id')
            ->whereIn('items.type', ['weapon', 'armor', 'accessory'])
            ->select([
                'battle_logs.id as battle_log_id',
                'battle_logs.dropped_character_item_id',
                'areas.city_id',
                'items.weapon_rank',
                'items.armor_rank',
                'items.accessory_rank',
                'items.rarity',
            ])
            ->get()
            ->filter(function ($row) {
                $rankSort = $this->rankSort($this->itemRank($row));
                if ($rankSort < 0) {
                    return false;
                }

                return $rankSort > $this->allowedMaxRankSort((int) ($row->city_id ?? 1));
            });

        if ($invalidRows->isEmpty()) {
            return;
        }

        $battleLogIds = $invalidRows->pluck('battle_log_id')->map(fn ($id) => (int) $id)->all();
        $characterItemIds = $invalidRows->pluck('dropped_character_item_id')->map(fn ($id) => (int) $id)->all();

        DB::transaction(function () use ($battleLogIds, $characterItemIds) {
            if (Schema::hasTable('exploration_loot_logs') && Schema::hasColumn('exploration_loot_logs', 'character_item_id')) {
                DB::table('exploration_loot_logs')
                    ->whereIn('character_item_id', $characterItemIds)
                    ->delete();
            }

            $battleLogUpdates = ['dropped_character_item_id' => null];
            if (Schema::hasColumn('battle_logs', 'dropped_item_id')) {
                $battleLogUpdates['dropped_item_id'] = null;
            }

            DB::table('battle_logs')
                ->whereIn('id', $battleLogIds)
                ->update($battleLogUpdates);

            DB::table('character_items')
                ->whereIn('id', $characterItemIds)
                ->delete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 回収した実体装備は安全に復元できないため、down は何もしません。
    }

    private function itemRank(object $row): string
    {
        return strtoupper((string) (
            $row->weapon_rank
            ?: $row->armor_rank
            ?: $row->accessory_rank
            ?: $row->rarity
            ?: ''
        ));
    }

    private function rankSort(string $rank): int
    {
        return [
            'G' => 0,
            'F' => 1,
            'E' => 2,
            'D' => 3,
            'C' => 4,
            'B' => 5,
            'A' => 6,
            'S' => 7,
            'SS' => 8,
            'SSS' => 9,
        ][$rank] ?? -1;
    }

    private function allowedMaxRankSort(int $cityId): int
    {
        $rank = match ($cityId) {
            1, 2 => 'E',
            3 => 'D',
            4 => 'C',
            5 => 'B',
            default => 'A',
        };

        return $this->rankSort($rank);
    }
};
