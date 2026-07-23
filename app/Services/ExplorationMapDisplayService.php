<?php

namespace App\Services;

use App\Models\Enemy;
use App\Models\ExplorationMap;
use Illuminate\Support\Collection;

class ExplorationMapDisplayService
{
    public function __construct(
        private readonly ExplorationMapDifficultyService $difficulty,
        private readonly ExplorationMapLegacyRewardService $legacyRewards,
    ) {}

    public function details(ExplorationMap $map, ?Collection $enemies = null): array
    {
        $variants = $map->normal_monster_variants_json ?? [];
        $enemies = ($enemies ?? Enemy::query()
            ->whereIn('id', collect($variants)->pluck('base_monster_id')->filter()->all())
            ->get())
            ->keyBy('id');
        $enemyLevels = $this->difficulty->enemyLevels($map, $enemies);
        $threatTier = $this->difficulty->threatTier(max($enemyLevels ?: [(int) $map->map_level]));
        $powerRange = $this->enemyPowerRange($map, $enemies);

        return [
            'dungeon_type' => config('exploration_maps.dungeon_type_labels.' . $map->dungeon_type, '未知の探索地'),
            'background_image' => config('exploration_maps.dungeon_card_backgrounds.' . $map->dungeon_type),
            'enemy_level_range' => $this->enemyLevelRange($enemyLevels),
            'enemy_power_range' => $this->formatPowerRange($powerRange),
            'enemy_power_min' => $powerRange['min'],
            'enemy_power_max' => $powerRange['max'],
            'threat_tier' => $threatTier['name'],
            'reward' => $this->rewardLabel($map),
            'environment' => collect($map->environment_effects_json ?? [])->map(fn (string $effect) => $this->environmentLabel($effect))->values()->all(),
        ];
    }

    /** @return array{min: int, max: int} */
    private function enemyPowerRange(ExplorationMap $map, Collection $enemies): array
    {
        $variants = $map->normal_monster_variants_json ?? [];
        $powerService = app(CharacterPowerService::class);
        $powers = [];

        foreach ($variants as $variant) {
            $base = $enemies->get((int) ($variant['base_monster_id'] ?? 0));
            if (!$base) {
                continue;
            }

            $enemy = clone $base;
            foreach (($variant['stat_modifiers'] ?? []) as $key => $percent) {
                $column = match ($key) {
                    'attack_percent' => 'str', 'defense_percent' => 'def', 'magic_percent' => 'mag',
                    'spirit_percent' => 'spr', 'speed_percent' => 'agi', 'hp_percent' => 'max_hp', default => null,
                };
                if ($column) {
                    $enemy->{$column} = max(1, (int) floor((int) $enemy->{$column} * (1 + ((float) $percent / 100))));
                }
            }
            $this->difficulty->applyToEnemy($enemy, $this->difficulty->targetLevel($enemy, $variant, (string) $map->map_grade));
            $powers[] = $powerService->fromEnemyStats($enemy->toArray());
        }

        if ($powers === []) {
            return ['min' => 0, 'max' => 0];
        }

        return ['min' => min($powers), 'max' => max($powers)];
    }

    private function formatPowerRange(array $range): string
    {
        if ($range['max'] <= 0) {
            return '不明';
        }

        return $range['min'] === $range['max']
            ? number_format($range['min'])
            : number_format($range['min']) . '〜' . number_format($range['max']);
    }

    private function enemyLevelRange(array $levels): string
    {
        if ($levels === []) {
            return '不明';
        }

        $min = min($levels);
        $max = max($levels);

        return $min === $max ? 'Lv' . $min : 'Lv' . $min . '〜' . $max;
    }

    private function rewardLabel(ExplorationMap $map): ?string
    {
        $modifiers = $map->reward_modifiers_json ?? [];
        $profile = config('exploration_maps.reward_profiles.' . $map->reward_profile, []);
        if (($profile['label'] ?? null) !== null
            && ($profile['modifiers'] ?? []) == $modifiers) {
            return (string) $profile['label'];
        }
        if (($modifiers['exp_multiplier'] ?? 1) > 1) return '経験値が20%多い';
        if (($modifiers['gold_multiplier'] ?? 1) > 1) return 'Goldが25%多い';

        if ($ancientFragment = $this->legacyRewards->ancientFragmentFor($map)) {
            return '古代片：' . $ancientFragment->displayName();
        }

        return null;
    }

    private function environmentLabel(string $effect): string
    {
        return [
            '極寒' => '身を切る寒さ', '氷晶を纏う' => '氷晶が辺りを覆う',
            '灼熱' => '灼熱の地帯', '豊かな鉱脈' => '鉱脈が露出している',
            '濃霧' => '濃い霧が立ち込める', '精霊の祝福' => '精霊の気配がある',
            '宝物庫' => '宝物庫の痕跡がある',
        ][$effect] ?? $effect;
    }
}
