<?php

namespace App\Console\Commands;

use App\Services\PendingMigrationPreflightService;
use Illuminate\Console\Command;

class PreflightPendingMigrations extends Command
{
    protected $signature = 'valzeria:preflight-pending-migrations {--allow-enemy-merge : 旧敵データ統合を承認済みとして通過させる}';

    protected $description = '未適用migrationの外部キー違反と敵データ統合影響を読み取り専用で検査します。';

    public function handle(PendingMigrationPreflightService $preflight): int
    {
        $result = $preflight->inspect();
        foreach ($result['blockers'] as $blocker) {
            $this->error("- {$blocker}");
        }

        $merge = $result['mergeSummary'];
        $hasMergeTargets = $merge['old_enemy_rows'] > 0;
        $this->line(sprintf(
            '旧敵統合の対象: 敵 %d件 / 戦闘ログ %d件 / 印マスタ %d件 / 所持印 %d件',
            $merge['old_enemy_rows'],
            $merge['battle_logs'],
            $merge['monster_marks'],
            $merge['character_marks'],
        ));

        if ($result['blockers'] !== []) {
            $this->error('migration実行前チェックに失敗しました。');

            return self::FAILURE;
        }

        if ($hasMergeTargets && !$this->option('allow-enemy-merge')) {
            $this->error('旧敵データの統合が発生します。件数を確認してから --allow-enemy-merge を付けて再実行してください。');

            return self::FAILURE;
        }

        $this->info('migration実行前チェックは通過しました。');

        return self::SUCCESS;
    }
}
