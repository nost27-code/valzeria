<?php

namespace App\Console\Commands;

use App\Models\PlayerValmon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class CleanupDuplicatePlayerValmons extends Command
{
    protected $signature = 'valzeria:cleanup-duplicate-valmons {--delete : Delete duplicate player valmons after writing a backup report}';

    protected $description = 'Remove duplicate player valmons, keeping the oldest acquired one for each character and Valmon master.';

    public function handle(): int
    {
        $groups = PlayerValmon::query()
            ->select('character_id', 'valmon_master_id', DB::raw('COUNT(*) as owned_count'))
            ->groupBy('character_id', 'valmon_master_id')
            ->having('owned_count', '>', 1)
            ->orderBy('character_id')
            ->orderBy('valmon_master_id')
            ->get();

        if ($groups->isEmpty()) {
            $this->info('重複ヴァルモンは見つかりませんでした。');
            return self::SUCCESS;
        }

        $report = [];
        $deleteIds = [];

        foreach ($groups as $group) {
            $valmons = PlayerValmon::with(['character:id,name', 'master:id,name'])
                ->where('character_id', $group->character_id)
                ->where('valmon_master_id', $group->valmon_master_id)
                ->orderByRaw('COALESCE(obtained_at, created_at)')
                ->orderBy('id')
                ->get();

            $keep = $valmons->first();
            $duplicates = $valmons->slice(1)->values();

            foreach ($duplicates as $duplicate) {
                $deleteIds[] = (int) $duplicate->id;
            }

            $report[] = [
                'character_id' => (int) $group->character_id,
                'character_name' => $keep?->character?->name,
                'valmon_master_id' => (int) $group->valmon_master_id,
                'valmon_name' => $keep?->master?->name,
                'keep_id' => $keep ? (int) $keep->id : null,
                'keep_obtained_at' => $this->dateValue($keep?->obtained_at),
                'delete_ids' => $duplicates->map(fn (PlayerValmon $valmon) => [
                    'id' => (int) $valmon->id,
                    'level' => (int) $valmon->level,
                    'exp' => (int) $valmon->exp,
                    'affection' => (int) $valmon->affection,
                    'is_partner' => (bool) $valmon->is_partner,
                    'obtained_at' => $this->dateValue($valmon->obtained_at),
                    'created_at' => $this->dateValue($valmon->created_at),
                ])->all(),
            ];
        }

        $this->info('重複グループ: ' . count($report) . ' / 削除候補: ' . count($deleteIds));

        $reportPath = storage_path('app/duplicate_player_valmons_' . now()->format('Ymd_His') . '.json');
        File::ensureDirectoryExists(dirname($reportPath));
        File::put($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info('削除前レポート: ' . $reportPath);

        if (!$this->option('delete')) {
            $this->warn('--delete が指定されていないため削除はしていません。');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($deleteIds): void {
            PlayerValmon::whereIn('id', $deleteIds)->delete();
            $this->normalizePartners();
        });

        $this->info('重複ヴァルモンを削除しました: ' . count($deleteIds) . '件');

        return self::SUCCESS;
    }

    private function normalizePartners(): void
    {
        $characterIds = PlayerValmon::query()
            ->select('character_id')
            ->groupBy('character_id')
            ->pluck('character_id');

        foreach ($characterIds as $characterId) {
            $partnerIds = PlayerValmon::where('character_id', $characterId)
                ->where('is_partner', true)
                ->orderByRaw('COALESCE(obtained_at, created_at)')
                ->orderBy('id')
                ->pluck('id');

            if ($partnerIds->count() > 1) {
                PlayerValmon::whereIn('id', $partnerIds->slice(1)->all())
                    ->update(['is_partner' => false]);
                continue;
            }

            if ($partnerIds->isEmpty()) {
                $oldestId = PlayerValmon::where('character_id', $characterId)
                    ->orderByRaw('COALESCE(obtained_at, created_at)')
                    ->orderBy('id')
                    ->value('id');

                if ($oldestId) {
                    PlayerValmon::whereKey($oldestId)->update(['is_partner' => true]);
                }
            }
        }
    }

    private function dateValue($date): ?string
    {
        return $date ? $date->toDateTimeString() : null;
    }
}
