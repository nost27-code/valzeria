<?php

namespace App\Services;

use App\Models\Area;
use App\Models\AreaDiscoveryLink;
use App\Models\Enemy;
use App\Models\Material;
use App\Models\MaterialDrop;
use App\Services\Enemy\EnemyStatGenerationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

class OuterWorldDungeonGenerationService
{
    private const DEFAULT_CITY_ID = 10;
    private const DEFAULT_DISCOVERY_POINT = 5000;
    private const DEFAULT_LEVEL_WIDTH = 2;

    private const ENEMY_TEMPLATES = [
        ['suffix' => 'の影獣', 'family_key' => 'beast', 'variant_key' => 'dark', 'role_key' => 'normal_weak', 'role' => '外縁の小魔物', 'type_name' => '獣', 'element' => '闇', 'weight' => 36, 'level_offset' => 0],
        ['suffix' => 'を漂う霊火', 'family_key' => 'spirit', 'variant_key' => 'abyss', 'role_key' => 'normal', 'role' => '外縁の魔物', 'type_name' => '精霊', 'element' => '深淵', 'weight' => 30, 'level_offset' => 0],
        ['suffix' => 'の境界兵', 'family_key' => 'soldier', 'variant_key' => 'ancient', 'role_key' => 'normal', 'role' => '外縁の兵', 'type_name' => '人型', 'element' => '古代', 'weight' => 24, 'level_offset' => 1],
        ['suffix' => 'の裂界獣', 'family_key' => 'demon', 'variant_key' => 'abyss', 'role_key' => 'strong', 'role' => '外縁の強敵', 'type_name' => '悪魔', 'element' => '深淵', 'weight' => 10, 'level_offset' => 1],
    ];

    /**
     * @return array<string, mixed>
     */
    public function plan(array $input): array
    {
        $name = $this->requiredString($input, 'name');
        $targetPower = isset($input['target_power']) ? max(1, (int) $input['target_power']) : null;
        $baseLevel = isset($input['base_level']) ? max(1, (int) $input['base_level']) : null;

        if ($targetPower === null && $baseLevel === null) {
            throw new InvalidArgumentException('target_power or base_level is required.');
        }

        $baseLevel ??= $this->levelForTargetPower($targetPower);
        $minLevel = $this->clampLevel($baseLevel);
        $maxLevel = $this->clampLevel(max($minLevel, $minLevel + self::DEFAULT_LEVEL_WIDTH));
        $cityId = max(1, (int) ($input['city_id'] ?? self::DEFAULT_CITY_ID));
        $areaId = isset($input['area_id']) ? max(1, (int) $input['area_id']) : null;
        $fromAreaId = isset($input['from_area_id']) ? max(1, (int) $input['from_area_id']) : null;
        $requiredPoint = max(1, (int) ($input['required_development_point'] ?? self::DEFAULT_DISCOVERY_POINT));
        $slug = $this->slug((string) ($input['slug'] ?? ''), $name);
        $sortOrder = (int) ($input['sort_order'] ?? $this->nextSortOrder($cityId));
        $unlockOrder = max(1, (int) ($input['unlock_order'] ?? 99));
        $description = trim((string) ($input['description'] ?? ''));
        $materialName = trim((string) ($input['material_name'] ?? ''));
        $materialCode = trim((string) ($input['material_code'] ?? ''));
        $materialDropRate = $input['material_drop_rate'] ?? null;

        if ($materialName !== '' && ($materialDropRate === null || !is_numeric($materialDropRate))) {
            throw new InvalidArgumentException('material_drop_rate is required when material_name is set.');
        }

        $area = [
            'id' => $areaId,
            'city_id' => $cityId,
            'name' => $name,
            'slug' => $slug,
            'description' => $description !== '' ? $description : '【外縁世界】 探索団が世界の外側で見つけた未踏領域。',
            'recommended_level_min' => $minLevel,
            'recommended_level_max' => $maxLevel,
            'unlock_order' => $unlockOrder,
            'unlock_required_area_id' => $fromAreaId,
            'area_kind' => 'outer_world',
            'clear_condition_type' => 'development_point',
            'development_required_point' => $requiredPoint,
            'is_route_area' => false,
            'sort_order' => $sortOrder,
        ];

        $enemies = $this->enemyPlans($name, $minLevel, $maxLevel);
        $boss = $this->bossPlan($name, $this->clampLevel($maxLevel + 3));
        $material = null;

        if ($materialName !== '') {
            $materialCode = $materialCode !== '' ? $materialCode : $this->materialCode($slug);
            $material = [
                'material_code' => $materialCode,
                'name' => $materialName,
                'drop_rate' => (float) $materialDropRate,
                'enemy_names' => array_column($enemies, 'name'),
            ];
        }

        return [
            'area' => $area,
            'discovery_link' => $fromAreaId ? [
                'from_type' => 'area',
                'from_id' => $fromAreaId,
                'to_type' => 'area',
                'to_id' => $areaId,
                'condition_type' => 'development_point',
                'required_development_point' => $requiredPoint,
                'requires_boss_defeated' => false,
                'rumor_text' => "外縁の先に「{$name}」へ続く気配があります。",
                'implementation_phase' => 'outer_world_generated',
                'sort_order' => $sortOrder,
            ] : null,
            'enemies' => [...$enemies, $boss],
            'material' => $material,
            'target_power' => $targetPower,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function apply(array $input): array
    {
        return DB::transaction(function () use ($input): array {
            $plan = $this->plan($input);
            $areaValues = $plan['area'];
            $areaId = $areaValues['id'];
            unset($areaValues['id']);

            $area = $areaId
                ? Area::updateOrCreate(['id' => $areaId], $areaValues)
                : Area::updateOrCreate(['slug' => $areaValues['slug']], $areaValues);

            $material = null;
            if ($plan['material']) {
                $material = Material::updateOrCreate(
                    ['material_code' => $plan['material']['material_code']],
                    $this->materialValues($plan['material'], $area)
                );
            }

            $enemies = [];
            foreach ($plan['enemies'] as $enemyPlan) {
                $enemy = Enemy::updateOrCreate(
                    ['area_id' => $area->id, 'name' => $enemyPlan['name']],
                    $this->enemyValues($area, $enemyPlan)
                );
                $enemies[] = $enemy;

                if ($material && !(bool) $enemy->is_boss && in_array($enemy->name, $plan['material']['enemy_names'], true)) {
                    MaterialDrop::updateOrCreate(
                        ['enemy_id' => $enemy->id, 'material_id' => $material->id],
                        [
                            'drop_rate' => (float) $plan['material']['drop_rate'],
                            'drop_first_clear_only' => false,
                            'drop_timing' => 'normal',
                            'is_active' => true,
                        ]
                    );
                }
            }

            if ($plan['discovery_link']) {
                $link = $plan['discovery_link'];
                $link['to_id'] = $area->id;
                AreaDiscoveryLink::updateOrCreate(
                    [
                        'from_type' => $link['from_type'],
                        'from_id' => $link['from_id'],
                        'to_type' => $link['to_type'],
                        'to_id' => $link['to_id'],
                    ],
                    $link
                );
            }

            return [
                'area' => $area,
                'enemies' => $enemies,
                'material' => $material,
                'plan' => $plan,
            ];
        });
    }

    private function levelForTargetPower(int $targetPower): int
    {
        $bestLevel = 1;
        $bestDiff = PHP_INT_MAX;

        for ($level = 1; $level <= 255; $level++) {
            $stats = app(EnemyStatGenerationService::class)->generate($level, 'standard', 'none', 'normal')['stats'];
            $power = app(CharacterPowerService::class)->fromEnemyStats($stats);
            $diff = abs($power - $targetPower);

            if ($diff < $bestDiff) {
                $bestLevel = $level;
                $bestDiff = $diff;
            }
        }

        return $bestLevel;
    }

    private function clampLevel(int $level): int
    {
        return app(EnemyStatGenerationService::class)->clampLevel($level);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function enemyPlans(string $areaName, int $minLevel, int $maxLevel): array
    {
        return array_map(function (array $template, int $index) use ($areaName, $minLevel, $maxLevel): array {
            $enemyLevel = min($maxLevel, $minLevel + (int) $template['level_offset']);
            $generated = app(EnemyStatGenerationService::class)->generate(
                $enemyLevel,
                $template['family_key'],
                $template['variant_key'],
                $template['role_key'],
            );

            return $template + [
                'name' => $areaName . $template['suffix'],
                'level' => $enemyLevel,
                'stats' => $generated['stats'],
                'stat_generation_version' => $generated['stat_generation_version'],
                'is_boss' => false,
                'sort_order' => $index + 1,
            ];
        }, self::ENEMY_TEMPLATES, array_keys(self::ENEMY_TEMPLATES));
    }

    /**
     * @return array<string, mixed>
     */
    private function bossPlan(string $areaName, int $level): array
    {
        $generated = app(EnemyStatGenerationService::class)->generate($level, 'demon', 'abyss', 'otherworld_boss');

        return [
            'name' => $areaName . 'の外縁主',
            'level' => $level,
            'family_key' => 'demon',
            'variant_key' => 'abyss',
            'role_key' => 'otherworld_boss',
            'role' => '外縁主',
            'type_name' => '悪魔',
            'element' => '深淵',
            'weight' => 0,
            'stats' => $generated['stats'],
            'stat_generation_version' => $generated['stat_generation_version'],
            'is_boss' => true,
            'sort_order' => 99,
        ];
    }

    /**
     * @param  array<string, mixed>  $enemyPlan
     * @return array<string, mixed>
     */
    private function enemyValues(Area $area, array $enemyPlan): array
    {
        $stats = $enemyPlan['stats'];
        $roleKey = (string) $enemyPlan['role_key'];
        $rewardMultiplier = match ($roleKey) {
            'strong' => 1.2,
            'otherworld_boss' => 2.4,
            'normal_weak' => 0.88,
            default => 1.0,
        };

        $values = [
            'area_id' => $area->id,
            'name' => $enemyPlan['name'],
            'level' => (int) $enemyPlan['level'],
            'max_hp' => $stats['hp'],
            'str' => $stats['attack'],
            'def' => $stats['defense'],
            'agi' => $stats['speed'],
            'mag' => $stats['magic'],
            'luk' => $stats['luck'],
            'exp_reward' => $this->expReward((int) $enemyPlan['level'], $rewardMultiplier),
            'gold_reward' => max(1, (int) round((10 + ((int) $enemyPlan['level'] * 2)) * $rewardMultiplier)),
            'appearance_weight' => (int) ($enemyPlan['weight'] ?? 0),
            'is_boss' => (bool) $enemyPlan['is_boss'],
            'sort_order' => (int) $enemyPlan['sort_order'],
        ];

        foreach ([
            'spr' => $stats['spirit'],
            'job_exp_reward' => (bool) $enemyPlan['is_boss'] ? 3 : ($roleKey === 'strong' ? 2 : 1),
            'role' => $enemyPlan['role'],
            'type_name' => $enemyPlan['type_name'],
            'element' => $enemyPlan['element'],
            'action_pattern' => 'standard',
            'drop_type' => 'outer_world',
            'enemy_level' => (int) $enemyPlan['level'],
            'family_key' => $enemyPlan['family_key'],
            'variant_key' => $enemyPlan['variant_key'],
            'role_key' => $enemyPlan['role_key'],
            'stat_generation_version' => $enemyPlan['stat_generation_version'],
            'is_stat_locked' => true,
            'generated_at' => now(),
            'manual_adjustment_note' => 'Outer world generated enemy. Review before public release.',
        ] as $column => $value) {
            if (Schema::hasColumn('enemies', $column)) {
                $values[$column] = $value;
            }
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $materialPlan
     * @return array<string, mixed>
     */
    private function materialValues(array $materialPlan, Area $area): array
    {
        $values = [
            'name' => $materialPlan['name'],
            'category' => '外縁素材',
            'rarity' => 'SR',
            'element' => null,
            'main_use' => '外縁世界の調合・素材用途',
            'npc_sale_price' => 0,
            'is_tradable' => false,
            'city_id' => (int) $area->city_id,
            'dungeon_id' => (int) $area->id,
            'source_area_id' => (int) $area->id,
            'drop_rate' => (float) $materialPlan['drop_rate'],
            'drop_first_clear_only' => false,
            'drop_timing' => 'normal',
            'material_type' => 'outer_world',
            'category_id' => 'outer_world',
            'rank_tier' => 3,
            'is_consumable' => true,
            'obtain_method' => $area->name . 'の通常探索',
        ];

        foreach ([
            'is_key_item' => false,
            'is_cash_item' => false,
            'usage_tags' => ['outer_world'],
            'acquisition_tags' => ['外縁世界', $area->name, '通常探索'],
            'market_hint' => '外縁世界でのみ見つかる素材です。',
        ] as $column => $value) {
            if (Schema::hasColumn('materials', $column)) {
                $values[$column] = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value;
            }
        }

        return $values;
    }

    private function expReward(int $enemyLevel, float $rewardMultiplier): int
    {
        $base = (0.18 * $enemyLevel * $enemyLevel) + (18 * $enemyLevel) - 85;

        return max(1, (int) round($base * $rewardMultiplier));
    }

    private function nextSortOrder(int $cityId): int
    {
        $max = (int) Area::where('city_id', $cityId)->max('sort_order');

        return max(($cityId * 100) + 900, $max + 10);
    }

    private function slug(string $slug, string $name): string
    {
        $slug = trim($slug);
        if ($slug !== '') {
            return $slug;
        }

        $base = Str::slug(Str::ascii($name), '_') ?: 'world';

        return 'outer_' . $base . '_' . substr(md5($name), 0, 8);
    }

    private function materialCode(string $slug): string
    {
        return 'OUTER_' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]+/', '_', $slug), 0, 40));
    }

    private function requiredString(array $input, string $key): string
    {
        $value = trim((string) ($input[$key] ?? ''));
        if ($value === '') {
            throw new InvalidArgumentException($key . ' is required.');
        }

        return $value;
    }
}
