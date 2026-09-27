<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneExplorationResults extends Command
{
    protected $signature = 'exploration:prune-results';

    protected $description = '24時間経過した探索結果の表示データだけを削除し、再送防止記録は保持する';

    public function handle(): int
    {
        $count = 0;
        DB::table('exploration_requests')->where('created_at', '<', now()->subDay())
            ->whereNotNull('battle_data')->select('id')->orderBy('id')
            ->chunkById(500, function ($rows) use (&$count) {
                $count += DB::table('exploration_requests')->whereIn('id', $rows->pluck('id'))->update(['battle_data' => null]);
            });
        $this->info("探索結果表示データを{$count}件整理しました。再送防止記録は保持しています。");

        return self::SUCCESS;
    }
}
