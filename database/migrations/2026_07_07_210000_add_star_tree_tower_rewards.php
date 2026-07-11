<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->ensureItemColumns();
        $this->ensureRewardClaimsTable();
        $this->upsertRewardWeapons();
    }

    public function down(): void
    {
        Schema::dropIfExists('tower_reward_claims');

        if (Schema::hasTable('items')) {
            DB::table('items')
                ->where('source_type', 'star_tree_tower_reward')
                ->update([
                    'is_active' => false,
                    'is_shop_item' => false,
                    'is_drop_enabled' => false,
                    'is_supply_enabled' => false,
                    'updated_at' => now(),
                ]);
        }
    }

    private function ensureItemColumns(): void
    {
        if (! Schema::hasTable('items')) {
            return;
        }

        Schema::table('items', function (Blueprint $table): void {
            if (! Schema::hasColumn('items', 'display_rank')) {
                $table->string('display_rank', 40)->nullable()->after('rarity');
            }
            if (! Schema::hasColumn('items', 'source_type')) {
                $table->string('source_type', 80)->nullable()->after('display_rank');
            }
            if (! Schema::hasColumn('items', 'is_evolvable')) {
                $table->boolean('is_evolvable')->default(true)->after('source_type');
            }
        });
    }

    private function ensureRewardClaimsTable(): void
    {
        if (Schema::hasTable('tower_reward_claims')) {
            return;
        }

        Schema::create('tower_reward_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('tower_key', 80)->default('star_tree_tower');
            $table->unsignedSmallInteger('floor');
            $table->string('reward_type', 40);
            $table->string('status', 20)->default('pending');
            $table->foreignId('selected_item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->foreignId('character_item_id')->nullable()->constrained('character_items')->nullOnDelete();
            $table->string('asset_type', 40)->nullable();
            $table->string('asset_path', 120)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();

            $table->unique(['character_id', 'tower_key', 'floor', 'reward_type'], 'tower_reward_claim_unique');
            $table->index(['character_id', 'tower_key', 'status'], 'tower_reward_claim_status_idx');
        });
    }

    private function upsertRewardWeapons(): void
    {
        if (! Schema::hasTable('items')) {
            return;
        }

        $config = config('star_tree_tower_rewards.weapon_rewards', []);
        $now = now();

        foreach ($config as $floor => $reward) {
            foreach (($reward['weapons'] ?? []) as $category => $weapon) {
                $externalId = 'STAR_TREE_TOWER_'.$floor.'_'.strtoupper((string) $category);
                $payload = [
                    'name' => (string) $weapon['name'],
                    'type' => 'weapon',
                    'description' => $this->description((int) $floor, (string) ($reward['display_rank'] ?? 'SPECIAL'), (float) ($reward['killer_damage_rate'] ?? 0)),
                    'rarity' => 'special',
                    'display_rank' => (string) ($reward['display_rank'] ?? ''),
                    'source_type' => 'star_tree_tower_reward',
                    'is_evolvable' => false,
                    'price' => 0,
                    'sell_price' => 0,
                    'hp_bonus' => (int) ($weapon['hp'] ?? 0),
                    'mp_bonus' => (int) ($weapon['sp'] ?? 0),
                    'str_bonus' => (int) ($weapon['atk'] ?? 0),
                    'def_bonus' => (int) ($weapon['def'] ?? 0),
                    'agi_bonus' => (int) ($weapon['spd'] ?? 0),
                    'mag_bonus' => (int) ($weapon['mag'] ?? 0),
                    'spr_bonus' => (int) ($weapon['spr'] ?? 0),
                    'luk_bonus' => (int) ($weapon['luk'] ?? 0),
                    'required_level' => 1,
                    'is_shop_item' => false,
                    'is_active' => true,
                    'sort_order' => 900000 + ((int) $floor * 100) + $this->categorySort((string) $category),
                    'unlock_city_id' => null,
                    'sub_type' => 'star_tree_tower_reward',
                    'element' => '森',
                    'weapon_category' => (string) $category,
                    'weapon_hand_type' => null,
                    'weapon_role' => '星樹の塔初回到達報酬',
                    'weapon_family_id' => 'star_tree_tower_'.$category,
                    'weapon_family_name' => '星樹の塔報酬',
                    'weapon_rank' => 'SPECIAL',
                    'weapon_rank_sort' => $this->rankSort((int) $floor),
                    'weapon_rank_multiplier' => 1,
                    'evolution_stage' => 0,
                    'next_item_external_id' => null,
                    'is_evolution_enabled' => false,
                    'is_drop_enabled' => false,
                    'affix_enabled' => false,
                    'is_supply_enabled' => false,
                    'max_enhance' => (int) config('star_tree_tower_rewards.weapon_max_enhance', 5),
                    'updated_at' => $now,
                ];

                $existing = DB::table('items')->where('external_item_id', $externalId)->first();
                if ($existing) {
                    DB::table('items')->where('id', $existing->id)->update($payload);
                    continue;
                }

                $payload['external_item_id'] = $externalId;
                $payload['created_at'] = $now;
                DB::table('items')->insert($payload);
            }
        }
    }

    private function description(int $floor, string $displayRank, float $killerRate): string
    {
        $rate = (int) round($killerRate * 100);

        $maxEnhance = (int) config('star_tree_tower_rewards.weapon_max_enhance', 5);

        return "{$floor}階初回到達報酬。{$displayRank}の特別な武器。進化不可、+{$maxEnhance}まで強化可。植物系の敵への与ダメージ+{$rate}%。";
    }

    private function rankSort(int $floor): int
    {
        return match ($floor) {
            50 => 75,
            70 => 85,
            90 => 95,
            default => 70,
        };
    }

    private function categorySort(string $category): int
    {
        return array_search($category, array_keys(config('star_tree_tower_rewards.weapon_categories', [])), true) + 1;
    }
};
