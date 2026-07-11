<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tower_floor_master')) {
            return;
        }

        $towerKey = (string) config('star_tree_tower.star_tree.tower_key', 'star_tree_tower');
        $floorCount = (int) config('star_tree_tower.star_tree.seed_floor_count', 100);
        $now = now();

        for ($floor = 1; $floor <= $floorCount; $floor++) {
            $layer = $this->layerFor($floor);
            $enemy = $this->enemyFor($floor, $layer['layer_name']);

            DB::table('tower_floor_master')->updateOrInsert(
                ['tower_key' => $towerKey, 'floor' => $floor],
                [
                    'layer_key' => $layer['layer_key'],
                    'layer_name' => $layer['layer_name'],
                    'enemy_name' => $enemy['enemy_name'],
                    'enemy_profile' => $enemy['enemy_profile'],
                    'enemy_type_name' => $enemy['enemy_type_name'],
                    'stamina_cost' => $this->staminaCostFor($floor),
                    'sort_order' => $floor,
                    'is_active' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        // Master backfill only. Do not delete tower floor rows on rollback.
    }

    /**
     * @return array{layer_key:string,layer_name:string}
     */
    private function layerFor(int $floor): array
    {
        return match (true) {
            $floor <= 9 => ['layer_key' => 'young_leaf', 'layer_name' => '若葉層'],
            $floor <= 19 => ['layer_key' => 'sun_dappled', 'layer_name' => '木漏れ日層'],
            $floor <= 29 => ['layer_key' => 'wind_branch', 'layer_name' => '風枝層'],
            $floor <= 39 => ['layer_key' => 'starlight', 'layer_name' => '星灯層'],
            $floor <= 49 => ['layer_key' => 'moon_dew', 'layer_name' => '月露層'],
            $floor <= 59 => ['layer_key' => 'sky_crown', 'layer_name' => '天冠層'],
            default => ['layer_key' => 'star_heaven', 'layer_name' => '星天層'],
        };
    }

    /**
     * @return array{enemy_name:string,enemy_profile:string,enemy_type_name:string}
     */
    private function enemyFor(int $floor, string $layerName): array
    {
        $profiles = $this->profilesFor($floor);
        $names = $this->namesFor($layerName);
        $profile = $floor % 10 === 0 ? 'speed' : $profiles[($floor - 1) % count($profiles)];

        return [
            'enemy_name' => $names[($floor - 1) % count($names)],
            'enemy_profile' => $profile,
            'enemy_type_name' => match ($profile) {
                'magical' => '魔法型',
                'hybrid' => '複合型',
                'speed' => '敏捷型',
                default => '物理型',
            },
        ];
    }

    /**
     * @return list<string>
     */
    private function profilesFor(int $floor): array
    {
        if ($floor <= 19) {
            return ['physical', 'physical', 'physical', 'physical', 'physical', 'physical', 'physical', 'magical', 'magical', 'hybrid'];
        }

        if ($floor <= 39) {
            return ['physical', 'physical', 'physical', 'physical', 'physical', 'magical', 'magical', 'magical', 'hybrid', 'hybrid'];
        }

        return ['physical', 'physical', 'physical', 'physical', 'magical', 'magical', 'magical', 'hybrid', 'hybrid', 'speed'];
    }

    /**
     * @return list<string>
     */
    private function namesFor(string $layerName): array
    {
        return match ($layerName) {
            '若葉層' => ['若葉スライム', '迷いリス', '苔まとう小鬼'],
            '木漏れ日層' => ['木漏れ日の蝶', '枝渡りの猿', '森の小精霊'],
            '風枝層' => ['風枝の鳥', '蔦甲虫', '葉隠れの狩人'],
            '星灯層' => ['星灯の精', '星葉の魔導獣', '淡光の樹人'],
            '月露層' => ['月露の鹿', '銀葉の幻影', '夜森の騎士'],
            '天冠層' => ['天冠の樹霊', '星葉の守人', '空枝の魔導獣'],
            default => ['星天の幻獣', '天葉の守護霊', '星樹の影'],
        };
    }

    private function staminaCostFor(int $floor): int
    {
        $schedule = (array) config('star_tree_tower.star_tree.stamina_cost_schedule', []);
        ksort($schedule, SORT_NUMERIC);
        $cost = 1;

        foreach ($schedule as $startFloor => $scheduledCost) {
            if ($floor >= (int) $startFloor) {
                $cost = (int) $scheduledCost;
            }
        }

        return max(1, min(20, $cost));
    }
};
