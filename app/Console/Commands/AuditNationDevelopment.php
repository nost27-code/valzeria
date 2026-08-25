<?php

namespace App\Console\Commands;

use App\Models\Nation;
use App\Services\Nation\NationDevelopmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class AuditNationDevelopment extends Command
{
    protected $signature = 'nation:audit-development
        {--nation-id=* : 確認対象の国家ID。省略時は全国家}
        {--repair : 台帳合計を正としてnations.development_expを修復する}';

    protected $description = '国家発展EXPキャッシュと国家資材台帳の合計を照合する';

    public function handle(NationDevelopmentService $development): int
    {
        $nationIds = collect($this->option('nation-id'))
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        $query = Nation::query()->orderBy('id');
        if ($nationIds->isNotEmpty()) {
            $query->whereIn('id', $nationIds);
        }
        $nations = $query->get();
        if ($nations->isEmpty()) {
            $this->error('確認対象の国家が見つかりません。');

            return self::FAILURE;
        }

        $repair = (bool) $this->option('repair');
        $rows = [];
        $mismatches = 0;
        foreach ($nations as $nation) {
            $ledgerTotal = $development->ledgerTotal($nation);
            $stored = (int) $nation->development_exp;
            $status = $stored === $ledgerTotal ? 'OK' : 'MISMATCH';

            if ($status === 'MISMATCH' && $repair) {
                [$stored, $ledgerTotal] = DB::transaction(function () use ($nation, $development): array {
                    $locked = Nation::query()->whereKey($nation->id)->lockForUpdate()->firstOrFail();
                    $expected = $development->ledgerTotal($locked);
                    $locked->update(['development_exp' => $expected]);

                    return [$expected, $expected];
                }, 3);
                $status = 'REPAIRED';
            } elseif ($status === 'MISMATCH') {
                $mismatches++;
            }

            $rows[] = [$nation->id, $nation->display_name, $stored, $ledgerTotal, $status];
        }

        $this->table(['国家ID', '国家名', '保存EXP', '台帳合計', '状態'], $rows);
        if ($mismatches > 0) {
            $this->error("{$mismatches}件の不整合があります。修復する場合だけ --repair を指定してください。");

            return self::FAILURE;
        }

        $this->info($repair ? '国家発展EXPの照合・修復が完了しました。' : '国家発展EXPは台帳と一致しています。');

        return self::SUCCESS;
    }
}
