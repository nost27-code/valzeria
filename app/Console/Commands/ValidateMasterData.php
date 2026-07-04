<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class ValidateMasterData extends Command
{
    protected $signature = 'valzeria:validate-master-data';

    protected $description = 'デプロイ前に主要マスタデータの整合性を検証します。';

    public function handle(): int
    {
        $checks = [
            '奥義データ' => 'valzeria:validate-job-arts',
        ];

        $failed = false;

        foreach ($checks as $label => $command) {
            $this->line("[{$label}] {$command}");
            $exitCode = Artisan::call($command);
            $output = trim(Artisan::output());

            if ($output !== '') {
                $this->line($output);
            }

            if ($exitCode !== self::SUCCESS) {
                $failed = true;
            }
        }

        if ($failed) {
            $this->error('マスタデータ整合性チェックに失敗しました。');
            return self::FAILURE;
        }

        $this->info('マスタデータ整合性チェックはすべて通過しました。');
        return self::SUCCESS;
    }
}
