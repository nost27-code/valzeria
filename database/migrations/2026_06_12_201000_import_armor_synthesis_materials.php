<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $path = database_path('data/armor_synthesis_material_additions.json');
        if (!is_file($path)) {
            return;
        }

        $rows = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $now = now();

        foreach ($rows as $row) {
            $materialCode = (string) $row['material_id'];
            $category = (string) ($row['material_category'] ?? 'armor_synthesis');

            DB::table('materials')->updateOrInsert(
                ['material_code' => $materialCode],
                [
                    'name' => (string) $row['material_name'],
                    'category' => $category,
                    'rarity' => strtoupper((string) ($row['material_grade'] ?? 'low')),
                    'element' => null,
                    'main_use' => '防具進化・鍛冶強化',
                    'npc_sale_price' => 0,
                    'is_tradable' => filter_var($row['is_tradeable'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'city_id' => $this->cityIdForMaterial((int) $row['material_id']),
                    'dungeon_id' => null,
                    'source_enemy_id' => null,
                    'drop_rate' => 0,
                    'drop_first_clear_only' => false,
                    'drop_timing' => null,
                    'material_type' => $category,
                    'category_id' => null,
                    'rank_tier' => $this->rankTier((string) ($row['material_grade'] ?? 'low')),
                    'is_consumable' => !in_array($category, ['unlock_key', 'key'], true),
                    'obtain_method' => (string) ($row['primary_source'] ?? ''),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        // マスタ再投入系のため、down では削除しない。
    }

    private function cityIdForMaterial(int $materialId): ?int
    {
        if ($materialId >= 5025 && $materialId <= 5044) {
            return intdiv($materialId - 5025, 2) + 1;
        }

        return null;
    }

    private function rankTier(string $grade): int
    {
        return match (strtolower($grade)) {
            'low', 'city_low' => 1,
            'mid', 'city_high' => 2,
            'high' => 3,
            'ss' => 4,
            'sss', 'epic', 'key' => 5,
            default => 1,
        };
    }
};
