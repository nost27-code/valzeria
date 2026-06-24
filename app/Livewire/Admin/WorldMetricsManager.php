<?php

namespace App\Livewire\Admin;

use App\Models\Character;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

class WorldMetricsManager extends Component
{
    public string $dateFrom = '';

    public string $dateTo = '';

    public function mount(): void
    {
        $this->dateFrom = now()->subDays(13)->toDateString();
        $this->dateTo = now()->toDateString();
    }

    public function render()
    {
        return view('livewire.admin.world-metrics-manager', $this->metricsData())
            ->layout('components.layouts.admin');
    }

    public function downloadCsv()
    {
        $data = $this->metricsData();
        $rows = [
            ['section', 'label', 'value_1', 'value_2', 'value_3', 'note'],
            ['meta', 'generated_at', $data['generatedAt']->format('Y/m/d H:i:s'), '', '', ''],
            ['meta', 'date_from', $data['dateFrom']->toDateString(), '', '', ''],
            ['meta', 'date_to', $data['dateTo']->toDateString(), '', '', ''],
        ];

        foreach ($data['summaryCards'] as $card) {
            $rows[] = ['summary', $card['label'], $card['raw'], '', '', $card['note']];
        }

        foreach ($data['dailyGoldLosses'] as $row) {
            $rows[] = [
                'daily_defeat_gold_loss',
                $row['date'],
                $row['gold_lost'],
                $row['defeat_count'],
                $row['characters_count'],
                'battle_logs.gold_lost',
            ];
        }

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, 'valzeria-world-metrics-' . now()->format('Ymd-His') . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function downloadGraphSvg()
    {
        $data = $this->metricsData();
        $svg = $this->graphSvg($data['graphRows'], $data['dateFrom'], $data['dateTo']);

        return response()->streamDownload(function () use ($svg) {
            echo $svg;
        }, 'valzeria-world-metrics-graph-' . now()->format('Ymd-His') . '.svg', [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
        ]);
    }

    private function metricsData(): array
    {
        [$dateFrom, $dateTo] = $this->dateRange();
        $wealth = $this->wealthSummary();
        $dailyGoldLosses = $this->dailyGoldLosses($dateFrom, $dateTo);
        $periodGoldLost = (int) collect($dailyGoldLosses)->sum('gold_lost');
        $periodDefeats = (int) collect($dailyGoldLosses)->sum('defeat_count');

        return [
            'generatedAt' => now(),
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'hasGoldLostColumn' => $this->hasGoldLostColumn(),
            'summaryCards' => [
                ['label' => '全プレイヤー貯金額', 'value' => number_format($wealth['bank_gold']) . 'G', 'raw' => $wealth['bank_gold'], 'note' => 'characters.bank_gold 合計'],
                ['label' => '全プレイヤー所持Gold', 'value' => number_format($wealth['money']) . 'G', 'raw' => $wealth['money'], 'note' => 'characters.money 合計'],
                ['label' => '世界の総資産', 'value' => number_format($wealth['total_gold']) . 'G', 'raw' => $wealth['total_gold'], 'note' => '所持Gold + 貯金'],
                ['label' => 'キャラクター数', 'value' => number_format($wealth['characters_count']) . '人', 'raw' => $wealth['characters_count'], 'note' => 'characters 全体'],
                ['label' => '期間内Gold喪失', 'value' => number_format($periodGoldLost) . 'G', 'raw' => $periodGoldLost, 'note' => $dateFrom->format('Y/m/d') . ' - ' . $dateTo->format('Y/m/d')],
                ['label' => '期間内敗北数', 'value' => number_format($periodDefeats) . '敗', 'raw' => $periodDefeats, 'note' => 'Gold喪失ログ対象'],
            ],
            'dailyGoldLosses' => $dailyGoldLosses,
            'graphRows' => $this->graphRows($dailyGoldLosses),
        ];
    }

    private function dateRange(): array
    {
        $from = $this->parseDate($this->dateFrom, now()->subDays(13))->startOfDay();
        $to = $this->parseDate($this->dateTo, now())->endOfDay();

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
            $this->dateFrom = $from->toDateString();
            $this->dateTo = $to->toDateString();
        }

        return [$from, $to];
    }

    private function parseDate(string $value, Carbon $fallback): Carbon
    {
        try {
            return Carbon::parse($value ?: $fallback->toDateString());
        } catch (\Throwable) {
            return $fallback->copy();
        }
    }

    private function wealthSummary(): array
    {
        if (!Schema::hasTable('characters')) {
            return ['characters_count' => 0, 'money' => 0, 'bank_gold' => 0, 'total_gold' => 0];
        }

        $money = (int) Character::query()->sum('money');
        $bankGold = Schema::hasColumn('characters', 'bank_gold')
            ? (int) Character::query()->sum('bank_gold')
            : 0;

        return [
            'characters_count' => Character::query()->count(),
            'money' => $money,
            'bank_gold' => $bankGold,
            'total_gold' => $money + $bankGold,
        ];
    }

    private function dailyGoldLosses(Carbon $dateFrom, Carbon $dateTo): array
    {
        if (!$this->hasGoldLostColumn()) {
            return [];
        }

        $dateExpression = DB::connection()->getDriverName() === 'sqlite'
            ? "date(created_at)"
            : "DATE(created_at)";

        $rows = DB::table('battle_logs')
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->where('gold_lost', '>', 0)
            ->selectRaw("{$dateExpression} as battle_date")
            ->selectRaw('SUM(gold_lost) as gold_lost')
            ->selectRaw('COUNT(*) as defeat_count')
            ->selectRaw('COUNT(DISTINCT character_id) as characters_count')
            ->groupBy('battle_date')
            ->orderByDesc('battle_date')
            ->get()
            ->keyBy('battle_date');

        $days = [];
        for ($day = $dateFrom->copy()->startOfDay(); $day->lte($dateTo); $day->addDay()) {
            $key = $day->toDateString();
            $row = $rows->get($key);

            $days[] = [
                'date' => $key,
                'gold_lost' => (int) ($row->gold_lost ?? 0),
                'defeat_count' => (int) ($row->defeat_count ?? 0),
                'characters_count' => (int) ($row->characters_count ?? 0),
            ];
        }

        return array_reverse($days);
    }

    private function graphRows(array $dailyGoldLosses): array
    {
        $rows = array_reverse($dailyGoldLosses);
        $maxGold = max(1, (int) collect($rows)->max('gold_lost'));
        $maxDefeats = max(1, (int) collect($rows)->max('defeat_count'));

        return collect($rows)
            ->map(function (array $row) use ($maxGold, $maxDefeats): array {
                $date = Carbon::parse($row['date']);

                return [
                    'date' => $row['date'],
                    'label' => $date->format('m/d'),
                    'gold_lost' => (int) $row['gold_lost'],
                    'defeat_count' => (int) $row['defeat_count'],
                    'characters_count' => (int) $row['characters_count'],
                    'gold_percent' => max(2, round(((int) $row['gold_lost'] / $maxGold) * 100, 2)),
                    'defeat_percent' => max(2, round(((int) $row['defeat_count'] / $maxDefeats) * 100, 2)),
                ];
            })
            ->values()
            ->all();
    }

    private function graphSvg(array $rows, Carbon $dateFrom, Carbon $dateTo): string
    {
        $width = 1200;
        $height = 620;
        $left = 84;
        $right = 40;
        $top = 96;
        $bottom = 118;
        $chartWidth = $width - $left - $right;
        $chartHeight = $height - $top - $bottom;
        $count = max(1, count($rows));
        $slot = $chartWidth / $count;
        $barWidth = max(12, min(34, $slot * 0.46));
        $maxGold = max(1, (int) collect($rows)->max('gold_lost'));
        $maxDefeats = max(1, (int) collect($rows)->max('defeat_count'));

        $svg = [];
        $svg[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $svg[] = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '">';
        $svg[] = '<rect width="1200" height="620" fill="#f8fafc"/>';
        $svg[] = '<text x="60" y="48" fill="#0f172a" font-size="28" font-weight="800" font-family="sans-serif">ヴァルゼリア 世界指標</text>';
        $svg[] = '<text x="60" y="76" fill="#64748b" font-size="15" font-weight="700" font-family="sans-serif">' . $this->escapeSvg($dateFrom->format('Y/m/d') . ' - ' . $dateTo->format('Y/m/d') . ' / 日別 敗北Gold喪失') . '</text>';
        $svg[] = '<rect x="' . $left . '" y="' . $top . '" width="' . $chartWidth . '" height="' . $chartHeight . '" fill="#ffffff" stroke="#e2e8f0"/>';

        for ($i = 0; $i <= 4; $i++) {
            $y = $top + ($chartHeight / 4) * $i;
            $value = (int) round($maxGold * (1 - $i / 4));
            $svg[] = '<line x1="' . $left . '" y1="' . $y . '" x2="' . ($left + $chartWidth) . '" y2="' . $y . '" stroke="#e2e8f0" stroke-width="1"/>';
            $svg[] = '<text x="24" y="' . ($y + 5) . '" fill="#64748b" font-size="13" font-weight="700" font-family="sans-serif">' . number_format($value) . 'G</text>';
        }

        $points = [];
        foreach ($rows as $index => $row) {
            $x = $left + ($slot * $index) + ($slot / 2);
            $goldHeight = ((int) $row['gold_lost'] / $maxGold) * $chartHeight;
            $defeatY = $top + $chartHeight - (((int) $row['defeat_count'] / $maxDefeats) * $chartHeight);
            $barX = $x - ($barWidth / 2);
            $barY = $top + $chartHeight - $goldHeight;

            $svg[] = '<rect x="' . round($barX, 2) . '" y="' . round($barY, 2) . '" width="' . round($barWidth, 2) . '" height="' . round(max(2, $goldHeight), 2) . '" rx="4" fill="#d97706"/>';
            $points[] = round($x, 2) . ',' . round($defeatY, 2);
            $svg[] = '<text x="' . round($x, 2) . '" y="' . ($top + $chartHeight + 28) . '" text-anchor="middle" fill="#475569" font-size="13" font-weight="700" font-family="sans-serif">' . $this->escapeSvg((string) $row['label']) . '</text>';
        }

        if (count($points) > 1) {
            $svg[] = '<polyline points="' . implode(' ', $points) . '" fill="none" stroke="#dc2626" stroke-width="4" stroke-linejoin="round" stroke-linecap="round"/>';
        }

        foreach ($points as $point) {
            [$x, $y] = explode(',', $point);
            $svg[] = '<circle cx="' . $x . '" cy="' . $y . '" r="5" fill="#dc2626" stroke="#ffffff" stroke-width="2"/>';
        }

        $svg[] = '<rect x="60" y="548" width="16" height="16" rx="3" fill="#d97706"/>';
        $svg[] = '<text x="84" y="562" fill="#334155" font-size="15" font-weight="800" font-family="sans-serif">失われたGold</text>';
        $svg[] = '<line x1="220" y1="556" x2="252" y2="556" stroke="#dc2626" stroke-width="4" stroke-linecap="round"/>';
        $svg[] = '<circle cx="236" cy="556" r="5" fill="#dc2626"/>';
        $svg[] = '<text x="264" y="562" fill="#334155" font-size="15" font-weight="800" font-family="sans-serif">敗北回数</text>';
        $svg[] = '<text x="60" y="590" fill="#64748b" font-size="13" font-weight="700" font-family="sans-serif">Gold最大値: ' . number_format($maxGold) . 'G / 敗北回数最大値: ' . number_format($maxDefeats) . '敗</text>';
        $svg[] = '</svg>';

        return implode("\n", $svg);
    }

    private function escapeSvg(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function hasGoldLostColumn(): bool
    {
        return Schema::hasTable('battle_logs') && Schema::hasColumn('battle_logs', 'gold_lost');
    }
}
