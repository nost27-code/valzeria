<?php

namespace App\Console\Commands;

use App\Models\Enemy;
use App\Services\Enemy\EnemyStatPreviewService;
use Illuminate\Console\Command;

class PreviewEnemyStats extends Command
{
    protected $signature = 'enemy:stats:preview {--area=} {--enemy=} {--unlocked-only} {--json}';

    protected $description = 'Preview generated enemy stats without updating the database.';

    public function handle(EnemyStatPreviewService $previewService): int
    {
        $previews = $this->queryEnemies()->get()->map(fn (Enemy $enemy): array => $previewService->preview($enemy));

        if ($this->option('json')) {
            $this->line(json_encode($previews->map(fn (array $preview): array => [
                'enemy_id' => $preview['enemy']->id,
                'area_id' => $preview['enemy']->area_id,
                'area_name' => $preview['enemy']->area?->name,
                'city_id' => $preview['enemy']->area?->city_id,
                'city_name' => $preview['enemy']->area?->city?->name,
                'name' => $preview['enemy']->name,
                'attribute' => $preview['enemy']->element,
                'is_boss' => (bool) $preview['enemy']->is_boss,
                'role' => $preview['enemy']->role,
                'current_level' => $preview['current_level'],
                'generated_level' => $preview['generated_level'],
                'generated' => $preview['generated'],
                'metadata' => $preview['metadata'],
                'locked' => $preview['is_stat_locked'],
                'version' => $preview['stat_generation_version'],
                'exp_reward' => (int) $preview['enemy']->exp_reward,
                'job_exp_reward' => (int) ($preview['enemy']->job_exp_reward ?? 0),
                'gold_reward' => (int) $preview['enemy']->gold_reward,
            ])->values(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $rows = $previews->map(function (array $preview): array {
            $enemy = $preview['enemy'];

            return [
                'ID' => $enemy->id,
                'Area' => $enemy->area?->name ?? '-',
                'Name' => $enemy->name,
                'Lv' => $preview['current_level'] . ' -> ' . $preview['generated_level'],
                'Keys' => "{$preview['metadata']['family_key']}/{$preview['metadata']['variant_key']}/{$preview['metadata']['role_key']}",
                'Current' => $this->statsLine($preview['current']),
                'Generated' => $this->statsLine($preview['generated']),
                'Diff' => $this->diffLine($preview['diff_percent']),
                'Locked' => $preview['is_stat_locked'] ? 'yes' : 'no',
            ];
        });

        if ($rows->isEmpty()) {
            $this->warn('No enemies matched.');
            return self::SUCCESS;
        }

        $this->table(array_keys($rows->first()), $rows->all());

        return self::SUCCESS;
    }

    private function queryEnemies()
    {
        return Enemy::query()
            ->with('area.city')
            ->when($this->option('area'), fn ($query, $areaId) => $query->where('area_id', (int) $areaId))
            ->when($this->option('enemy'), fn ($query, $enemyId) => $query->where('id', (int) $enemyId))
            ->when($this->option('unlocked-only'), fn ($query) => $query->where('is_stat_locked', false))
            ->orderBy('area_id')
            ->orderBy('is_boss')
            ->orderBy('id');
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function statsLine(array $stats): string
    {
        return sprintf(
            'HP%s ATK%s DEF%s SPD%s MAG%s SPR%s LUK%s',
            number_format($stats['max_hp']),
            number_format($stats['str']),
            number_format($stats['def']),
            number_format($stats['agi']),
            number_format($stats['mag']),
            number_format($stats['spr']),
            number_format($stats['luk'])
        );
    }

    /**
     * @param  array<string, int>  $diff
     */
    private function diffLine(array $diff): string
    {
        return sprintf(
            'HP%+d%% ATK%+d%% DEF%+d%% SPD%+d%% MAG%+d%% SPR%+d%% LUK%+d%%',
            $diff['max_hp'],
            $diff['str'],
            $diff['def'],
            $diff['agi'],
            $diff['mag'],
            $diff['spr'],
            $diff['luk'],
        );
    }
}
