<?php

namespace App\Console\Commands;

use App\Services\AreaSevenMarkCompensationService;
use Illuminate\Console\Command;

class CompensateAreaSevenMonsterMarks extends Command
{
    protected $signature = 'monster-marks:compensate-area7-candidates
        {--execute : dry-runではなく実際に処理する}
        {--rollback : 補填付与ではなくロールバックを対象にする}
        {--confirmation= : 実行確認文字列}';

    protected $description = '各街のエリア7で落ちなかったボス候補印を、同エリアの他4印平均まで補填する';

    public function handle(AreaSevenMarkCompensationService $service): int
    {
        $execute = (bool) $this->option('execute');
        $rollback = (bool) $this->option('rollback');
        $confirmation = trim((string) $this->option('confirmation'));

        if ($execute) {
            $expected = $rollback
                ? 'rollback-area7-mark-compensation'
                : 'apply-area7-mark-compensation';
            if (! hash_equals($expected, $confirmation)) {
                $this->error("実行には --confirmation={$expected} が必要です。");

                return self::FAILURE;
            }
        }

        try {
            $result = match (true) {
                $rollback && $execute => $service->rollback(),
                $rollback => $service->previewRollback(),
                $execute => $service->execute(),
                default => $service->preview(),
            };
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
