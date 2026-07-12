<?php

namespace App\Console\Commands;

use App\Services\ReleaseReadinessService;
use Illuminate\Console\Command;

class ValidateReleaseReadiness extends Command
{
    protected $signature = 'valzeria:validate-release-readiness {--all : OFF中の追加コンテンツも含めて必須マスタを検証する}';

    protected $description = '公開中コンテンツと主要参照のデプロイ準備状態を検証します。';

    public function handle(ReleaseReadinessService $readiness): int
    {
        $issues = $readiness->issues((bool) $this->option('all'));
        if ($issues !== []) {
            $this->error('リリース準備チェックに失敗しました。');
            foreach ($issues as $issue) {
                $this->line("- {$issue}");
            }

            return self::FAILURE;
        }

        $this->info('リリース準備チェックは通過しました。');
        return self::SUCCESS;
    }
}
