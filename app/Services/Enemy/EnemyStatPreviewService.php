<?php

namespace App\Services\Enemy;

use App\Models\Enemy;
use Illuminate\Support\Facades\DB;

class EnemyStatPreviewService
{
    public function __construct(
        private readonly EnemyLevelResolver $levelResolver,
        private readonly EnemyStatGenerationService $generator,
        private readonly EnemyStatMetadataGuesser $guesser,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(Enemy $enemy): array
    {
        $enemy->loadMissing('area.city');
        $metadata = $this->metadataFor($enemy);
        $enemyLevel = $this->resolvedEnemyLevel($enemy, $metadata['role_key']);

        $generated = $this->generator->generate(
            $enemyLevel,
            $metadata['family_key'],
            $metadata['variant_key'],
            $metadata['role_key'],
        );

        $current = $this->currentStats($enemy);
        $mapped = $this->mapGeneratedStats($generated['stats']);
        $mapped = $this->applyHybridOffenseFloor($enemy, $mapped);
        $mapped = $this->applyBossProgressionFloor($enemy, $mapped);

        return [
            'enemy' => $enemy,
            'metadata' => $metadata,
            'current_level' => (int) $enemy->level,
            'generated_level' => (int) $generated['enemy_level'],
            'current' => $current,
            'generated' => $mapped,
            'diff_percent' => $this->diffPercent($current, $mapped),
            'stat_generation_version' => $generated['stat_generation_version'],
            'is_stat_locked' => (bool) ($enemy->is_stat_locked ?? true),
        ];
    }

    private function resolvedEnemyLevel(Enemy $enemy, string $roleKey): int
    {
        $note = (string) ($enemy->manual_adjustment_note ?? '');
        $hasManualLevel = (int) ($enemy->enemy_level ?? 0) > 0
            && ! str_starts_with($note, 'auto_migration_note:');

        if ($hasManualLevel) {
            return (int) $enemy->enemy_level;
        }

        if ($enemy->area) {
            return $this->levelResolver->resolveEnemyLevel($enemy->area, $roleKey);
        }

        return (int) ($enemy->enemy_level ?: $enemy->level);
    }

    /**
     * @return array<string, mixed>
     */
    public function apply(Enemy $enemy, bool $ignoreLock = false): array
    {
        $preview = $this->preview($enemy);
        if (!$ignoreLock && (bool) ($enemy->is_stat_locked ?? true)) {
            return ['applied' => false, 'reason' => 'locked', 'preview' => $preview];
        }

        $generated = $preview['generated'];
        $enemy->fill([
            'level' => $preview['generated_level'],
            'enemy_level' => $preview['generated_level'],
            'max_hp' => $generated['max_hp'],
            'str' => $generated['str'],
            'def' => $generated['def'],
            'agi' => $generated['agi'],
            'mag' => $generated['mag'],
            'spr' => $generated['spr'],
            'luk' => $generated['luk'],
            'family_key' => $preview['metadata']['family_key'],
            'variant_key' => $preview['metadata']['variant_key'],
            'role_key' => $preview['metadata']['role_key'],
            'stat_generation_version' => $preview['stat_generation_version'],
            'generated_at' => now(),
        ])->save();

        return ['applied' => true, 'reason' => null, 'preview' => $preview];
    }

    /**
     * @return array{family_key:string,variant_key:string,role_key:string,manual_adjustment_note:?string}
     */
    public function metadataFor(Enemy $enemy): array
    {
        $guessed = $this->guesser->guess($enemy->getAttributes());

        return [
            'family_key' => trim((string) ($enemy->family_key ?? '')) ?: $guessed['family_key'],
            'variant_key' => trim((string) ($enemy->variant_key ?? '')) ?: $guessed['variant_key'],
            'role_key' => trim((string) ($enemy->role_key ?? '')) ?: $guessed['role_key'],
            'manual_adjustment_note' => trim((string) ($enemy->manual_adjustment_note ?? '')) ?: $guessed['manual_adjustment_note'],
        ];
    }

    /**
     * @return array{max_hp:int,str:int,def:int,agi:int,mag:int,spr:int,luk:int}
     */
    private function currentStats(Enemy $enemy): array
    {
        return [
            'max_hp' => (int) $enemy->max_hp,
            'str' => (int) $enemy->str,
            'def' => (int) $enemy->def,
            'agi' => (int) $enemy->agi,
            'mag' => (int) $enemy->mag,
            'spr' => (int) ($enemy->spr ?? 0),
            'luk' => (int) $enemy->luk,
        ];
    }

    /**
     * @param  array{hp:int,attack:int,defense:int,magic:int,spirit:int,speed:int,luck:int}  $stats
     * @return array{max_hp:int,str:int,def:int,agi:int,mag:int,spr:int,luk:int}
     */
    private function mapGeneratedStats(array $stats): array
    {
        return [
            'max_hp' => $stats['hp'],
            'str' => $stats['attack'],
            'def' => $stats['defense'],
            'agi' => $stats['speed'],
            'mag' => $stats['magic'],
            'spr' => $stats['spirit'],
            'luk' => $stats['luck'],
        ];
    }

    /**
     * @param  array{max_hp:int,str:int,def:int,agi:int,mag:int,spr:int,luk:int}  $stats
     * @return array{max_hp:int,str:int,def:int,agi:int,mag:int,spr:int,luk:int}
     */
    private function applyBossProgressionFloor(Enemy $enemy, array $stats): array
    {
        $config = (array) config('enemy_stat_generation.offense_floors.boss_progression', []);
        if (! (bool) ($config['enabled'] ?? false) || ! (bool) $enemy->is_boss || ! $enemy->area) {
            return $stats;
        }

        $maxRecommendedLevel = (int) ($config['max_recommended_level'] ?? 179);
        $currentMinLevel = (int) ($enemy->area->recommended_level_min ?? $enemy->level ?? 0);
        if ($currentMinLevel > $maxRecommendedLevel) {
            return $stats;
        }

        $previous = $this->previousRouteBoss($enemy, $maxRecommendedLevel);
        if (! $previous) {
            return $stats;
        }

        $previousOffense = max((int) $previous->str, (int) $previous->mag);
        $currentOffense = max($stats['str'], $stats['mag']);
        $growthRate = (float) ($config['minimum_growth_rate'] ?? 0.03);
        $lateStartLevel = (int) ($config['late_growth_start_level'] ?? 0);
        if ($lateStartLevel > 0 && $currentMinLevel >= $lateStartLevel) {
            $growthRate = (float) ($config['late_minimum_growth_rate'] ?? $growthRate);
        }

        $target = max(
            $previousOffense + (int) ($config['minimum_growth_flat'] ?? 1),
            (int) ceil($previousOffense * (1.0 + $growthRate))
        );

        if ($currentOffense >= $target) {
            return $stats;
        }

        $mainStat = $this->mainOffenseStat($enemy, $stats);
        $stats[$mainStat] = max($stats[$mainStat], $target);

        return $stats;
    }

    /**
     * @param  array{max_hp:int,str:int,def:int,agi:int,mag:int,spr:int,luk:int}  $stats
     * @return array{max_hp:int,str:int,def:int,agi:int,mag:int,spr:int,luk:int}
     */
    private function applyHybridOffenseFloor(Enemy $enemy, array $stats): array
    {
        $typeName = (string) ($enemy->type_name ?? '');
        $floors = (array) config('enemy_stat_generation.offense_floors.hybrid_offense_by_type', []);
        $floor = (array) ($floors[$typeName] ?? []);
        $primary = (string) ($floor['primary'] ?? '');
        $secondary = (string) ($floor['secondary'] ?? '');

        if ($primary === '' || $secondary === '' || ! array_key_exists($primary, $stats) || ! array_key_exists($secondary, $stats)) {
            return $stats;
        }

        $primaryRatio = isset($floor['primary_vs_secondary']) ? (float) $floor['primary_vs_secondary'] : 0.0;
        if ($primaryRatio > 0) {
            $stats[$primary] = max($stats[$primary], (int) round($stats[$secondary] * $primaryRatio));
        }

        $secondaryRatio = isset($floor['secondary_vs_primary']) ? (float) $floor['secondary_vs_primary'] : 0.0;
        if ($secondaryRatio > 0) {
            $stats[$secondary] = max($stats[$secondary], (int) round($stats[$primary] * $secondaryRatio));
        }

        return $stats;
    }

    private function previousRouteBoss(Enemy $enemy, int $maxRecommendedLevel): ?object
    {
        $areaSort = (int) ($enemy->area->sort_order ?? 0);

        return DB::table('enemies')
            ->join('areas', 'areas.id', '=', 'enemies.area_id')
            ->where('enemies.is_boss', true)
            ->where('areas.recommended_level_min', '<=', $maxRecommendedLevel)
            ->where(function ($query) use ($areaSort, $enemy): void {
                $query->where('areas.sort_order', '<', $areaSort)
                    ->orWhere(function ($query) use ($areaSort, $enemy): void {
                        $query->where('areas.sort_order', $areaSort)
                            ->where('enemies.id', '<', (int) $enemy->id);
                    });
            })
            ->orderByDesc('areas.sort_order')
            ->orderByDesc('enemies.id')
            ->select('enemies.id', 'enemies.name', 'enemies.str', 'enemies.mag')
            ->first();
    }

    /**
     * @param  array{max_hp:int,str:int,def:int,agi:int,mag:int,spr:int,luk:int}  $stats
     */
    private function mainOffenseStat(Enemy $enemy, array $stats): string
    {
        $typeName = (string) ($enemy->type_name ?? '');
        if ($typeName === '魔法型') {
            return 'mag';
        }

        return $stats['mag'] > $stats['str'] ? 'mag' : 'str';
    }

    /**
     * @param  array<string, int>  $current
     * @param  array<string, int>  $generated
     * @return array<string, int>
     */
    private function diffPercent(array $current, array $generated): array
    {
        $diff = [];
        foreach ($generated as $key => $value) {
            $base = max(1, (int) ($current[$key] ?? 0));
            $diff[$key] = (int) round(($value - $base) / $base * 100);
        }

        return $diff;
    }
}
