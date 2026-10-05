<?php

namespace App\Console\Commands;

use App\Services\NamelessPreparationService;
use App\Services\NamelessTownService;
use Illuminate\Console\Command;
use Throwable;

class PrepareNamelessRelics extends Command
{
    protected $signature = 'nameless:prepare {--apply : 対象5本のDB移行だけを実行} {--register-town : OFFのまま工房街を事前登録} {--json : 確認結果をJSONで出力}';
    protected $description = '遺物機能をOFFのままDB移行・制約・街登録の準備状態を確認する';

    public function handle(NamelessPreparationService $preparation, NamelessTownService $town): int
    {
        try {
            if ($this->option('apply') || $this->option('register-town')) {
                $preparation->assertOff();
            }
            if ($this->option('apply')) {
                $preparation->applyMigrations();
            }
            if ($this->option('register-town')) {
                $town->installTown(true);
            }
            $status = $preparation->status();
            $this->line(json_encode($status, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return $status['ready'] && $status['town_count'] === 1 ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception instanceof \RuntimeException ? $exception->getMessage() : '準備処理に失敗しました。移行状態を確認してください。');
            return self::FAILURE;
        }
    }
}
