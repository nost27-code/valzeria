<?php

namespace App\Console\Commands;

use App\Services\Admin\SecurityAnomalyDetectionService;
use Illuminate\Console\Command;

class DetectSecurityAnomalies extends Command
{
    protected $signature = 'security:detect-anomalies';

    protected $description = 'ゲーム内ログから不正・異常候補をルールベースで検知する';

    public function handle(SecurityAnomalyDetectionService $detection): int
    {
        if (! $detection->schemaReady()) {
            $this->error('異常検知用migrationが未適用です。');

            return self::FAILURE;
        }

        $result = $detection->scan();
        if ($result['skipped']) {
            $this->warn('別の異常検知処理が実行中のため、この走査をスキップしました。');

            return self::SUCCESS;
        }
        if ($result['retention_deferred'] > 0) {
            $this->warn("期限切れログイン記録の整理を {$result['retention_deferred']}件延期しました。記録は保持し、次回に再確認します。");
        }
        $this->info("異常検知完了: 新規 {$result['created']}件 / 更新 {$result['updated']}件");

        return self::SUCCESS;
    }
}
