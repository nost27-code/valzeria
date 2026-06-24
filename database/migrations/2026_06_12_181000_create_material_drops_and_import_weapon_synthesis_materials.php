<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('material_drops')) {
            Schema::create('material_drops', function (Blueprint $table) {
                $table->id();
                $table->foreignId('enemy_id')->constrained()->cascadeOnDelete();
                $table->foreignId('material_id')->constrained()->cascadeOnDelete();
                $table->decimal('drop_rate', 6, 2)->default(0);
                $table->boolean('drop_first_clear_only')->default(false);
                $table->string('drop_timing')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['enemy_id', 'material_id']);
                $table->index(['enemy_id', 'is_active']);
            });
        }

        $this->importWeaponSynthesisMaterials();
        $this->normalizeWeaponRecipeIngredients();
    }

    public function down(): void
    {
        Schema::dropIfExists('material_drops');
    }

    private function importWeaponSynthesisMaterials(): void
    {
        $path = database_path('data/weapon_synthesis_material_additions.json');
        if (!is_file($path)) {
            return;
        }

        $rows = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $now = now();

        foreach ($rows as $row) {
            $category = trim((string) ($row['素材カテゴリ'] ?? '武器合成素材'));
            $materialType = match ($category) {
                '武器共通進化素材' => 'weapon_common',
                '武器カテゴリ素材' => 'weapon_category',
                '都市素材' => 'weapon_city',
                '都市高位素材' => 'weapon_city_high',
                '進化解放キー' => 'weapon_unlock_key',
                default => 'weapon_synthesis',
            };

            DB::table('materials')->updateOrInsert(
                ['material_code' => trim((string) $row['material_id'])],
                [
                    'name' => trim((string) $row['素材名']),
                    'category' => $category,
                    'rarity' => trim((string) ($row['レア度'] ?? 'N')),
                    'element' => null,
                    'main_use' => trim((string) ($row['主用途'] ?? '武器進化')),
                    'npc_sale_price' => 0,
                    'is_tradable' => false,
                    'city_id' => trim((string) ($row['city_id'] ?? '')) !== '' ? (int) $row['city_id'] : null,
                    'dungeon_id' => null,
                    'source_enemy_id' => null,
                    'drop_rate' => 0,
                    'drop_first_clear_only' => false,
                    'drop_timing' => null,
                    'material_type' => $materialType,
                    'category_id' => null,
                    'rank_tier' => $this->rankTier((string) ($row['レア度'] ?? 'N')),
                    'is_consumable' => $materialType !== 'weapon_unlock_key',
                    'obtain_method' => trim((string) ($row['設計メモ'] ?? '')),
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }

    private function normalizeWeaponRecipeIngredients(): void
    {
        if (!Schema::hasTable('weapon_evolution_recipe_ingredients')) {
            return;
        }

        $map = [
            'MAT_WEAPON_FRAGMENT' => ['WEV0001', '武器の欠片'],
            'MAT_WEAPON_CRYSTAL' => ['WEV0002', '武具の結晶'],
            'MAT_WEAPON_CORE' => ['WEV0003', '武具の核'],
            'MAT_ANCIENT_PART' => ['WEV0004', '古代武具片'],
            'MAT_STARDUST_FORGE' => ['WEV0005', '星屑の鍛材'],
            'TOKEN_SECRET_MATERIAL' => ['WEV0006', '秘境の星砂'],
            'TOKEN_LEGEND_MATERIAL' => ['WEV0007', '伝説の武具紋章'],
            'MAT_SLASH_FRAGMENT' => ['WEV0008', '斬撃の欠片'],
            'MAT_SLASH_CRYSTAL' => ['WEV0009', '斬撃の結晶'],
            'MAT_SLASH_CORE' => ['WEV0010', '斬撃の核'],
            'MAT_PIERCE_FRAGMENT' => ['WEV0011', '刺突の欠片'],
            'MAT_PIERCE_CRYSTAL' => ['WEV0012', '刺突の結晶'],
            'MAT_PIERCE_CORE' => ['WEV0013', '刺突の核'],
            'MAT_BLUNT_FRAGMENT' => ['WEV0014', '打撃の欠片'],
            'MAT_BLUNT_CRYSTAL' => ['WEV0015', '打撃の結晶'],
            'MAT_BLUNT_CORE' => ['WEV0016', '打撃の核'],
            'MAT_RANGED_FRAGMENT' => ['WEV0017', '射撃の欠片'],
            'MAT_RANGED_CRYSTAL' => ['WEV0018', '射撃の結晶'],
            'MAT_RANGED_CORE' => ['WEV0019', '射撃の核'],
            'MAT_MAGIC_FRAGMENT' => ['WEV0020', '魔導の欠片'],
            'MAT_MAGIC_CRYSTAL' => ['WEV0021', '魔導の結晶'],
            'MAT_MAGIC_CORE' => ['WEV0022', '魔導の核'],
            'TOKEN_SECRET_DUNGEON_MATERIAL' => ['WEV0004', '古代武具片'],
            'TOKEN_SECRET_HIGH_MATERIAL' => ['WEV0005', '星屑の鍛材'],
        ];

        foreach ($map as $from => [$to, $name]) {
            DB::table('weapon_evolution_recipe_ingredients')
                ->where('ingredient_id', $from)
                ->update([
                    'ingredient_id' => $to,
                    'ingredient_name' => $name,
                    'updated_at' => now(),
                ]);
        }
    }

    private function rankTier(string $rarity): int
    {
        return match (strtoupper($rarity)) {
            'N', 'N+' => 1,
            'R', 'R+' => 2,
            'SR', 'SR+' => 3,
            'SSR' => 4,
            'SSSR', 'KEY', 'EPIC' => 5,
            default => 1,
        };
    }
};
