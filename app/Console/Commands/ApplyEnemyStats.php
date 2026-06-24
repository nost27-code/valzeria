<?php

namespace App\Console\Commands;

use App\Models\Enemy;
use App\Services\Enemy\EnemyStatPreviewService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ApplyEnemyStats extends Command
{
    protected $signature = 'enemy:stats:apply
        {--area=}
        {--enemy=}
        {--all}
        {--unlocked-only}
        {--include-locked}
        {--backup-key=}
        {--force}
        {--dry-run}';

    protected $description = 'Apply generated enemy stats to unlocked enemies only.';

    public function handle(EnemyStatPreviewService $previewService): int
    {
        if (!$this->option('all') && !$this->option('area') && !$this->option('enemy')) {
            $this->error('Use --enemy=ID, --area=ID, or --all.');
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $enemies = $this->queryEnemies()->get();
        if ($enemies->isEmpty()) {
            $this->warn('No enemies matched.');
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info('Dry run: no database changes will be made.');
        } elseif (!$this->option('force') && !$this->confirm('敵ステータスを更新します。続行しますか？')) {
            return self::SUCCESS;
        }

        $applied = 0;
        $skipped = 0;
        $backedUp = 0;
        $rows = [];
        $includeLocked = (bool) $this->option('include-locked');
        $backupKey = trim((string) $this->option('backup-key')) ?: 'pre_' . config('enemy_stat_generation.version');

        foreach ($enemies as $enemy) {
            $preview = $previewService->preview($enemy);
            if (!$includeLocked && (bool) ($enemy->is_stat_locked ?? true)) {
                $skipped++;
                $rows[] = [$enemy->id, $enemy->name, 'locked', $preview['current_level'] . ' -> ' . $preview['generated_level']];
                continue;
            }

            if (!$dryRun) {
                if ($this->backupEnemyStats($enemy, $backupKey)) {
                    $backedUp++;
                }
                $previewService->apply($enemy, $includeLocked);
            }

            $applied++;
            $rows[] = [$enemy->id, $enemy->name, $dryRun ? 'dry-run' : 'applied', $preview['current_level'] . ' -> ' . $preview['generated_level']];
        }

        $this->table(['ID', 'Name', 'Status', 'Lv'], $rows);
        $this->info("Applied: {$applied}, skipped locked: {$skipped}");
        if (!$dryRun) {
            $this->info("Backed up old stats: {$backedUp} rows ({$backupKey})");
        }

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

    private function backupEnemyStats(Enemy $enemy, string $backupKey): bool
    {
        if (!Schema::hasTable('enemy_stat_snapshots')) {
            return false;
        }

        $exists = DB::table('enemy_stat_snapshots')
            ->where('enemy_id', $enemy->id)
            ->where('snapshot_key', $backupKey)
            ->exists();

        if ($exists) {
            return false;
        }

        DB::table('enemy_stat_snapshots')->insert([
            'enemy_id' => $enemy->id,
            'snapshot_key' => $backupKey,
            'enemy_name' => $enemy->name,
            'level' => (int) $enemy->level,
            'enemy_level' => $enemy->enemy_level,
            'max_hp' => (int) $enemy->max_hp,
            'str' => (int) $enemy->str,
            'def' => (int) $enemy->def,
            'agi' => (int) $enemy->agi,
            'mag' => (int) $enemy->mag,
            'spr' => (int) ($enemy->spr ?? 0),
            'luk' => (int) $enemy->luk,
            'stat_generation_version' => $enemy->stat_generation_version,
            'captured_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    }
}
