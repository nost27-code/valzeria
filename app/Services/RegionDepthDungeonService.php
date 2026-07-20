<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Character;
use App\Models\CharacterMaterial;
use App\Models\CharacterRegionDungeonRecord;
use App\Models\CharacterRegionDungeonRun;
use App\Models\Material;
use App\Models\RegionDepthDungeon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RegionDepthDungeonService
{
    public function definition(string $dungeonKey): array
    {
        if (Schema::hasTable('region_depth_dungeons')) {
            $dungeon = RegionDepthDungeon::query()->where('key', $dungeonKey)->first();
            if ($dungeon) {
                return [
                    'key' => $dungeon->key,
                    'enabled' => $dungeon->is_enabled,
                    'name' => $dungeon->name,
                    'description' => $dungeon->description,
                    'city_id' => $dungeon->city_id,
                    'area_id' => $dungeon->area_id,
                    'source_area_id' => $dungeon->source_area_id,
                    'entry' => ['gold' => $dungeon->entry_gold, 'materials' => $dungeon->entry_materials ?? []],
                    'danger_increase_percent' => $dungeon->danger_increase_percent,
                    'scaling' => [
                        'base_stat_multipliers' => $dungeon->base_stat_multipliers ?? [],
                        'base_exp_multiplier' => $dungeon->base_exp_multiplier,
                        'base_job_exp' => $dungeon->base_job_exp,
                        'main_stat_per_danger' => $dungeon->main_stat_per_danger,
                        'hp_per_danger' => $dungeon->hp_per_danger,
                        'agi_luk_per_danger' => $dungeon->agi_luk_per_danger,
                        'exp_per_danger' => $dungeon->exp_per_danger,
                        'exp_multiplier_cap' => $dungeon->exp_multiplier_cap,
                    ],
                    'job_exp' => [
                        'cap' => $dungeon->job_exp_cap,
                        'danger_per_guaranteed_bonus' => $dungeon->danger_per_guaranteed_bonus,
                        'remainder_percent_divisor' => $dungeon->remainder_percent_divisor,
                        'require_positive_base' => true,
                    ],
                    'ore_vein' => $dungeon->ore_vein ?? [],
                    'public_log' => ['minimum_danger' => $dungeon->public_log_minimum_danger],
                ] + $this->visualsFor($dungeon->key);
            }
        }

        return config("region_depth_dungeons.{$dungeonKey}", []) + $this->visualsFor($dungeonKey);
    }

    private function visualsFor(string $dungeonKey): array
    {
        return match ($dungeonKey) {
            'granberg_black_furnace' => [
                'card_background_image' => 'card_bg/dungeon_04_12.webp',
                'symbol_image' => 'symbol/dungeon_04_12.webp',
            ],
            default => [],
        };
    }

    public function enabledForCity(int $cityId): array
    {
        if (Schema::hasTable('region_depth_dungeons')) {
            return RegionDepthDungeon::query()
                ->where('city_id', $cityId)
                ->where('is_enabled', true)
                ->orderBy('id')
                ->get()
                ->map(fn (RegionDepthDungeon $dungeon) => $this->definition($dungeon->key))
                ->all();
        }

        return collect(config('region_depth_dungeons', []))
            ->filter(fn (array $definition) => ($definition['enabled'] ?? false) && (int) ($definition['city_id'] ?? 0) === $cityId)
            ->map(fn (array $definition, string $key) => array_merge(['key' => $key], $definition))
            ->values()
            ->all();
    }

    public function areaFor(string $dungeonKey): ?Area
    {
        $definition = $this->definition($dungeonKey);
        if (!empty($definition['area_id'])) {
            return Area::find((int) $definition['area_id']);
        }

        $key = (string) ($definition['area_key'] ?? '');
        return $key === '' ? null : Area::where('slug', $key)->first();
    }

    public function sourceAreaFor(string $dungeonKey): ?Area
    {
        $areaId = (int) ($this->definition($dungeonKey)['source_area_id'] ?? 0);

        return $areaId > 0 ? Area::find($areaId) : $this->areaFor($dungeonKey);
    }

    public function baselineScaling(Area $sourceArea, Area $baselineArea): array
    {
        $stats = ['max_hp' => 'hp', 'str' => 'str', 'def' => 'def', 'agi' => 'agi', 'mag' => 'mag', 'spr' => 'spr', 'luk' => 'luk'];
        $source = $sourceArea->enemies()->where('is_boss', false)->get();
        $baseline = $baselineArea->enemies()->where('is_boss', false)->get();
        if ($source->isEmpty() || $baseline->isEmpty()) {
            throw new \RuntimeException('敵候補と強さ基準には、通常敵が1体以上いるダンジョンを選んでください。');
        }

        $multipliers = [];
        foreach ($stats as $column => $key) {
            $sourceAverage = max(1.0, (float) $source->avg($column));
            $multipliers[$key] = round(max(1.0, (float) $baseline->avg($column) / $sourceAverage), 4);
        }

        return [
            'base_stat_multipliers' => $multipliers,
            'base_exp_multiplier' => round(max(1.0, (float) $baseline->avg('exp_reward') / max(1.0, (float) $source->avg('exp_reward'))), 4),
        ];
    }

    public function isRegionDepthArea(Area|int $area): bool
    {
        return $this->keyForArea($area) !== null;
    }

    public function keyForArea(Area|int $area): ?string
    {
        $areaId = $area instanceof Area ? (int) $area->id : $area;
        if (Schema::hasTable('region_depth_dungeons')) {
            return RegionDepthDungeon::query()->where('area_id', $areaId)->value('key');
        }

        foreach (array_keys(config('region_depth_dungeons', [])) as $key) {
            if ((int) ($this->areaFor($key)?->id ?? 0) === $areaId) {
                return $key;
            }
        }

        return null;
    }

    public function activeRun(Character $character): ?CharacterRegionDungeonRun
    {
        return CharacterRegionDungeonRun::where('character_id', $character->id)->where('status', 'active')->latest('id')->first();
    }

    public function canExplore(Character $character, Area|int $area): bool
    {
        $run = $this->activeRun($character);

        return $run && (int) $run->area_id === (int) ($area instanceof Area ? $area->id : $area);
    }

    public function entrySummary(Character $character, string $dungeonKey): array
    {
        $definition = $this->definition($dungeonKey);
        $materials = [];
        foreach (($definition['entry']['materials'] ?? []) as $entry) {
            $material = Material::where('material_code', (string) ($entry['code'] ?? ''))->first();
            $owned = $material ? (int) CharacterMaterial::where('character_id', $character->id)->where('material_id', $material->id)->value('quantity') : 0;
            $required = (int) ($entry['quantity'] ?? 0);
            $materials[] = ['code' => (string) ($entry['code'] ?? ''), 'name' => $material?->displayName() ?? (string) ($entry['code'] ?? ''), 'required' => $required, 'owned' => $owned, 'shortage' => max(0, $required - $owned)];
        }

        $gold = (int) ($definition['entry']['gold'] ?? 0);

        return ['materials' => $materials, 'gold' => $gold, 'gold_owned' => (int) $character->money, 'gold_shortage' => max(0, $gold - (int) $character->money)];
    }

    public function canEnter(Character $character, string $dungeonKey): array
    {
        $definition = $this->definition($dungeonKey);
        $area = $this->areaFor($dungeonKey);
        if (!($definition['enabled'] ?? false) || !$area) return ['ok' => false, 'error' => 'この追加ダンジョンは現在利用できません。'];
        if ((int) $character->highest_city_id < (int) ($definition['city_id'] ?? 0)) return ['ok' => false, 'error' => ($definition['name'] ?? 'この追加ダンジョン') . 'はこの街に到達後に挑戦できます。'];
        if ($this->activeRun($character)) return ['ok' => false, 'error' => 'すでに追加ダンジョンを探索中です。'];
        if (app(ExplorationStateService::class)->hasActiveExploration($character)) return ['ok' => false, 'error' => '進行中の探索を終えてから入場してください。'];
        $entry = $this->entrySummary($character, $dungeonKey);
        if ($entry['gold_shortage'] > 0 || collect($entry['materials'])->contains(fn (array $material) => $material['shortage'] > 0)) return ['ok' => false, 'error' => '入場料が足りません。', 'entry' => $entry];

        return ['ok' => true, 'area' => $area, 'entry' => $entry];
    }

    public function enter(Character $character, string $dungeonKey): CharacterRegionDungeonRun
    {
        return DB::transaction(function () use ($character, $dungeonKey) {
            $lockedCharacter = Character::lockForUpdate()->findOrFail($character->id);
            $check = $this->canEnter($lockedCharacter, $dungeonKey);
            if (!($check['ok'] ?? false)) throw new \RuntimeException((string) ($check['error'] ?? '入場できません。'));
            $area = $check['area'];
            foreach (($this->definition($dungeonKey)['entry']['materials'] ?? []) as $entry) {
                $material = Material::where('material_code', (string) $entry['code'])->firstOrFail();
                $owned = CharacterMaterial::where('character_id', $lockedCharacter->id)->where('material_id', $material->id)->lockForUpdate()->firstOrFail();
                $quantity = (int) $entry['quantity'];
                if ((int) $owned->quantity < $quantity) throw new \RuntimeException('入場料の素材が足りません。');
                $owned->decrement('quantity', $quantity);
            }
            app(GoldService::class)->spend($lockedCharacter, (int) ($this->definition($dungeonKey)['entry']['gold'] ?? 0), 'region_depth_entry', ($this->definition($dungeonKey)['name'] ?? '追加ダンジョン') . 'の入場料', Area::class, $area->id, ['dungeon_key' => $dungeonKey]);
            app(ExplorationStateService::class)->reset($lockedCharacter, $area->id);

            return CharacterRegionDungeonRun::create(['character_id' => $lockedCharacter->id, 'dungeon_key' => $dungeonKey, 'area_id' => $area->id, 'status' => 'active', 'entered_at' => now()]);
        });
    }

    public function dangerLabel(int $dangerRate): string
    {
        return match (true) { $dangerRate >= 1000 => '炉神域', $dangerRate >= 700 => '終炉', $dangerRate >= 400 => '黒炉界', $dangerRate >= 200 => '灼獄', $dangerRate >= 100 => '魔境', $dangerRate >= 75 => '深層', $dangerRate >= 50 => '危険', $dangerRate >= 25 => '警戒', default => '安定' };
    }

    public function dangerIncreasePercent(string $dungeonKey): int { return min(100, max(0, (int) ($this->definition($dungeonKey)['danger_increase_percent'] ?? 0))); }
    public function shouldIncreaseDanger(string $dungeonKey, ?int $roll = null): bool { $chance = $this->dangerIncreasePercent($dungeonKey); return $chance > 0 && ($roll ?? random_int(1, 100)) <= $chance; }
    public function enemyPrefix(int $dangerRate): string { return match (true) { $dangerRate >= 1000 => '炉神級の', $dangerRate >= 700 => '終炉の', $dangerRate >= 400 => '深黒の', $dangerRate >= 200 => '灼獄の', $dangerRate >= 100 => '黒炉の', $dangerRate >= 75 => '暴走する', $dangerRate >= 50 => '炉熱を帯びた', $dangerRate >= 25 => '硬質化した', default => '' }; }

    public function baseEnemyStatMultipliers(string $dungeonKey): array
    {
        $multipliers = $this->definition($dungeonKey)['scaling']['base_stat_multipliers'] ?? [];

        return collect(['hp', 'str', 'def', 'agi', 'mag', 'spr', 'luk'])->mapWithKeys(fn (string $stat) => [$stat => max(1.0, (float) ($multipliers[$stat] ?? 1.0))])->all();
    }

    public function enemyMultipliers(int $dangerRate, string $dungeonKey = 'granberg_black_furnace'): array
    {
        $scaling = $this->definition($dungeonKey)['scaling'] ?? [];
        return ['main' => 1 + $dangerRate * (float) ($scaling['main_stat_per_danger'] ?? 0), 'hp' => 1 + $dangerRate * (float) ($scaling['hp_per_danger'] ?? 0), 'agi_luk' => 1 + $dangerRate * (float) ($scaling['agi_luk_per_danger'] ?? 0)];
    }

    public function baseExpMultiplier(string $dungeonKey): float { return max(1.0, (float) ($this->definition($dungeonKey)['scaling']['base_exp_multiplier'] ?? 1.0)); }
    public function baseJobExp(string $dungeonKey, int $enemyJobExp): int { $configured = (int) ($this->definition($dungeonKey)['scaling']['base_job_exp'] ?? 0); return $configured > 0 ? $configured : max(0, $enemyJobExp); }
    public function expMultiplier(int $dangerRate, string $dungeonKey = 'granberg_black_furnace'): float { $scaling = $this->definition($dungeonKey)['scaling'] ?? []; return min((float) ($scaling['exp_multiplier_cap'] ?? 1.0), 1 + $dangerRate * (float) ($scaling['exp_per_danger'] ?? 0)); }

    public function calculateJobExp(int $baseJobExp, int $dangerRate, ?int $rollBasisPoints = null, string $dungeonKey = 'granberg_black_furnace'): array
    {
        $config = $this->definition($dungeonKey)['job_exp'] ?? [];
        $cap = (int) ($config['cap'] ?? 3);
        if ($baseJobExp <= 0 && ($config['require_positive_base'] ?? true)) return ['base' => $baseJobExp, 'danger_rate' => $dangerRate, 'guaranteed_bonus' => 0, 'remainder' => 0, 'remainder_bonus' => 0, 'total_before_cap' => 0, 'total' => 0, 'cap' => $cap];
        $unit = max(1, (int) ($config['danger_per_guaranteed_bonus'] ?? 200));
        $remainder = max(0, $dangerRate) % $unit;
        $chance = (int) round($remainder / max(1, (float) ($config['remainder_percent_divisor'] ?? 2)) * 100);
        $remainderBonus = $remainder > 0 && ($rollBasisPoints ?? random_int(1, 10000)) <= $chance ? 1 : 0;
        $beforeCap = $baseJobExp + intdiv(max(0, $dangerRate), $unit) + $remainderBonus;

        return ['base' => $baseJobExp, 'danger_rate' => $dangerRate, 'guaranteed_bonus' => intdiv(max(0, $dangerRate), $unit), 'remainder' => $remainder, 'remainder_chance_percent' => $chance / 100, 'remainder_bonus' => $remainderBonus, 'total_before_cap' => $beforeCap, 'total' => min($cap, $beforeCap), 'cap' => $cap];
    }

    public function recordVictoryRewards(Character $character, int $expGained, int $jobExpGained, int $dangerRate, int $chainCount): array
    {
        $run = $this->activeRun($character);
        if (!$run) return [];
        $run->increment('total_exp', max(0, $expGained)); $run->increment('total_job_exp', max(0, $jobExpGained));
        $run->forceFill(['max_danger_rate' => max((int) $run->max_danger_rate, $dangerRate), 'max_chain_count' => max((int) $run->max_chain_count, $chainCount)])->save();

        return ['run' => $run->fresh()];
    }

    public function rollOreVein(Character $character, int $dangerRate, int $chainCount, ?\App\Models\Enemy $enemy = null, string $dungeonKey = 'granberg_black_furnace'): ?array
    {
        $config = $this->definition($dungeonKey)['ore_vein'] ?? [];
        $interval = (int) ($config['chain_interval'] ?? 0);
        if ($interval <= 0 || $chainCount <= 0 || $chainCount % $interval !== 0) return null;
        $pool = $dangerRate >= (int) ($config['high_grade_unlock_danger'] ?? PHP_INT_MAX) ? [['MAT_COMMON_MAGIC_ORE', 55, 1, 3], ['WEV0026', 25, 1, 1], ['5031', 15, 1, 1], ['WEV0039', 3, 1, 1], ['5032', 2, 1, 1]] : [['MAT_COMMON_MAGIC_ORE', 60, 1, 3], ['WEV0026', 25, 1, 1], ['5031', 15, 1, 1]];
        $roll = random_int(1, array_sum(array_column($pool, 1))); $cursor = 0;
        foreach ($pool as [$code, $weight, $min, $max]) { $cursor += $weight; if ($roll <= $cursor) { $material = Material::where('material_code', $code)->first(); if (!$material) return null; $quantity = random_int($min, $max); $drop = null; for ($i = 0; $i < $quantity; $i++) $drop = app(DropService::class)->grantMaterialReward($character, $material, 'ore_vein', $enemy); return array_merge($drop ?? [], ['quantity' => $quantity, 'rare' => in_array($code, ['WEV0039', '5032'], true)]); } }
        return null;
    }

    public function finalize(Character $character, string $reason): ?CharacterRegionDungeonRun
    {
        return DB::transaction(function () use ($character, $reason) {
            $run = CharacterRegionDungeonRun::where('character_id', $character->id)->where('status', 'active')->lockForUpdate()->latest('id')->first();
            if (!$run) return null;
            $record = CharacterRegionDungeonRecord::firstOrCreate(['character_id' => $character->id, 'dungeon_key' => $run->dungeon_key]);
            $newDanger = (int) $run->max_danger_rate > (int) $record->best_danger_rate;
            if ($newDanger) { $record->best_danger_rate = $run->max_danger_rate; $record->best_danger_at = now(); }
            if ((int) $run->max_chain_count > (int) $record->best_chain_count) { $record->best_chain_count = $run->max_chain_count; $record->best_chain_at = now(); }
            if ((int) $run->total_exp > (int) $record->best_total_exp) { $record->best_total_exp = $run->total_exp; $record->best_total_exp_at = now(); }
            $record->save();
            $run->forceFill(['status' => $reason === 'returned' ? 'returned' : 'defeated', 'end_reason' => $reason, 'ended_at' => now(), 'new_danger_record' => $newDanger])->save();
            $definition = $this->definition($run->dungeon_key); $minimum = (int) ($definition['public_log']['minimum_danger'] ?? 100);
            $personalRank = $newDanger ? $this->leaderboard($character, $run->dungeon_key)['personal_rank'] : null;
            if ($newDanger && $personalRank !== null && $personalRank <= 5 && (int) $run->max_danger_rate >= $minimum && !$run->public_log_sent_at) {
                $rate = (int) $run->max_danger_rate;
                app(PublicLogService::class)->addLog('region_depth_dungeon', '【' . ($definition['name'] ?? '追加ダンジョン') . '・最高記録】' . $character->name . 'さんが危険度' . number_format($rate) . '%「' . $this->dangerLabel($rate) . '」へ到達しました！', $character, $rate >= 1000 ? 3 : ($rate >= 400 ? 2 : 1));
                $run->forceFill(['public_log_sent_at' => now()])->save();
            }

            return $run->fresh();
        });
    }

    public function leaderboard(Character $character, string $dungeonKey, int $limit = 10): array
    {
        $records = CharacterRegionDungeonRecord::query()
            ->with('character:id,name,icon_path')
            ->where('dungeon_key', $dungeonKey)
            ->where('best_danger_rate', '>', 0)
            ->orderByDesc('best_danger_rate')
            ->orderByDesc('best_chain_count')
            ->orderByDesc('best_total_exp')
            ->orderBy('id')
            ->get();

        $personal = $records->firstWhere('character_id', $character->id);
        $personalRank = $personal ? $records->search(fn (CharacterRegionDungeonRecord $record) => (int) $record->id === (int) $personal->id) + 1 : null;

        return [
            'personal_rank' => $personalRank,
            'others' => $records
                ->values()
                ->map(fn (CharacterRegionDungeonRecord $record, int $index) => ['rank' => $index + 1, 'record' => $record])
                ->reject(fn (array $entry) => (int) $entry['record']->character_id === (int) $character->id)
                ->take(max(1, $limit))
                ->values()
                ->all(),
        ];
    }

    public function payload(Character $character, string $dungeonKey): array
    {
        $run = $this->activeRun($character); $record = CharacterRegionDungeonRecord::where('character_id', $character->id)->where('dungeon_key', $dungeonKey)->first();
        $state = app(ExplorationStateService::class)->currentFor($character); $danger = $run && $state ? (int) $state->danger_rate : 0;

        return ['run' => $run, 'record' => $record, 'ranking' => $this->leaderboard($character, $dungeonKey), 'entry' => $this->entrySummary($character, $dungeonKey), 'danger_label' => $this->dangerLabel($danger), 'multipliers' => $this->enemyMultipliers($danger, $dungeonKey), 'job_exp' => $this->calculateJobExp(1, $danger, 10001, $dungeonKey)];
    }
}
