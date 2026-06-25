<?php

namespace App\Livewire\Admin;

use App\Models\Character;
use App\Models\City;
use App\Models\ContactMessage;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

class AdminDashboard extends Component
{
    public function render()
    {
        $data = $this->dashboardData();

        return view('livewire.admin.admin-dashboard', $data)->layout('components.layouts.admin');
    }

    public function downloadAiText()
    {
        $data = $this->dashboardData();
        $content = $this->formatAiText($data);
        $filename = 'valzeria-admin-analytics-' . now()->format('Ymd-His') . '.txt';

        return response()->streamDownload(function () use ($content) {
            echo $content;
        }, $filename, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    public function downloadCsv()
    {
        $data = $this->dashboardData();
        $content = $this->formatCsv($data);
        $filename = 'valzeria-admin-analytics-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($content) {
            echo $content;
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function dashboardData(): array
    {
        $now = now();
        $todayStart = $now->copy()->startOfDay();
        $sevenDaysAgo = $now->copy()->subDays(7);

        $totalUsers = User::count();
        $todayNewUsers = User::where('created_at', '>=', $todayStart)->count();
        $totalCharacters = Character::count();
        $onlineWindowMinutes = max(1, (int) config('services.pochi_game_portal.online_window_minutes', 5));
        $onlineCharacters = Character::where('last_seen_at', '>=', $now->copy()->subMinutes($onlineWindowMinutes))->count();
        $todayLoginUsers = Character::where('last_seen_at', '>=', $todayStart)->distinct('user_id')->count('user_id');
        $weeklyLoginUsers = Character::where('last_seen_at', '>=', $sevenDaysAgo)->distinct('user_id')->count('user_id');
        $averageLevel = (float) Character::avg('level');
        $newContactMessages = Schema::hasTable('contact_messages')
            ? ContactMessage::where('status', 'new')->count()
            : 0;
        $changedJobCharacters = $this->changedJobCharacters();
        $retention = $this->retention($sevenDaysAgo);

        return [
            'generatedAt' => $now,
            'summaryCards' => [
                ['label' => '登録ユーザー数', 'value' => number_format($totalUsers), 'note' => 'users 全体'],
                ['label' => '今日の新規ユーザー数', 'value' => number_format($todayNewUsers), 'note' => $todayStart->format('Y/m/d')],
                ['label' => '継続率', 'value' => $retention['rate_label'], 'note' => $retention['note']],
                ['label' => '同時接続数', 'value' => number_format($onlineCharacters), 'note' => "直近{$onlineWindowMinutes}分の活動キャラ"],
                ['label' => '新規受信メール', 'value' => number_format($newContactMessages), 'note' => '未読の問い合わせ', 'url' => route('admin.contact-messages')],
                ['label' => 'ログイン数', 'value' => number_format($todayLoginUsers), 'note' => '今日ログインしたユーザー'],
                ['label' => '7日以内ログイン', 'value' => number_format($weeklyLoginUsers), 'note' => '直近7日で活動あり'],
                ['label' => '平均レベル', 'value' => number_format($averageLevel, 1), 'note' => 'characters 平均'],
                ['label' => '転職済み人数', 'value' => number_format($changedJobCharacters), 'note' => '2職以上の履歴あり'],
                ['label' => 'チャンプ挑戦数', 'value' => number_format($this->champChallengeCount()), 'note' => '累計挑戦ログ'],
            ],
            'cityDistribution' => $this->cityDistribution($totalCharacters),
            'dungeonLosses' => $this->dungeonLosses(),
            'popularJobs' => $this->popularJobs($totalCharacters),
            'popularWeapons' => $this->popularWeapons(),
            'dropOffPoints' => $this->dropOffPoints(),
        ];
    }

    private function formatAiText(array $data): string
    {
        $lines = [
            '# ヴァルゼリアの冒険者 管理ダッシュボード集計',
            '',
            '目的: 以下の運営データから、プレイヤーが詰まっている地点、離脱要因、バランス調整候補、次に確認すべき仮説を分析してください。',
            '集計時刻: ' . $data['generatedAt']->format('Y/m/d H:i:s'),
            '',
            '## 主要指標',
        ];

        foreach ($data['summaryCards'] as $card) {
            $lines[] = '- ' . $card['label'] . ': ' . $card['value'] . ' (' . $card['note'] . ')';
        }

        $sections = [
            '到達街分布' => ['cityDistribution', fn ($row) => sprintf(
                '- %s: %s人 / %.1f%% / 推奨%s%s',
                $row['city'],
                number_format((int) $row['count']),
                (float) $row['percent'],
                $row['recommended'],
                $row['is_initial'] ? ' / 初期街' : ''
            )],
            '敗北が多いダンジョン' => ['dungeonLosses', fn ($row) => sprintf(
                '- %s (%s): %s敗 / 敗北率 %.1f%% / 総戦闘%s',
                $row['name'],
                $row['city'],
                number_format((int) $row['count']),
                (float) $row['rate'],
                number_format((int) $row['total'])
            )],
            '離脱が多い地点' => ['dropOffPoints', fn ($row) => sprintf(
                '- %s: %s人 / 離脱候補の %.1f%%',
                $row['name'],
                number_format((int) $row['count']),
                (float) $row['percent']
            )],
            'よく使われる職業' => ['popularJobs', fn ($row) => sprintf(
                '- %s: %s人 / %.1f%%',
                $row['name'],
                number_format((int) $row['count']),
                (float) $row['percent']
            )],
            'よく装備されている武器' => ['popularWeapons', fn ($row) => sprintf(
                '- %s: %s人',
                $row['name'],
                number_format((int) $row['count'])
            )],
        ];

        foreach ($sections as $title => [$key, $formatter]) {
            $lines[] = '';
            $lines[] = '## ' . $title;
            if (empty($data[$key])) {
                $lines[] = '- データなし';
                continue;
            }

            foreach ($data[$key] as $row) {
                $lines[] = $formatter($row);
            }
        }

        $lines[] = '';
        $lines[] = '## AIに見てほしい観点';
        $lines[] = '- 到達街分布で人数が偏っている場所はどこか';
        $lines[] = '- 序盤で止まっている場合、導線・敵の強さ・報酬・回復導線のどれが疑わしいか';
        $lines[] = '- 敗北が多いダンジョンと離脱地点に関連があるか';
        $lines[] = '- よく使われる職業/武器が偏っている場合、選択肢の魅力や性能差に問題がありそうか';
        $lines[] = '- 次に見るべき追加データ、改善施策、A/Bテスト案を提案してください';
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function formatCsv(array $data): string
    {
        $rows = [];
        $rows[] = ['section', 'label', 'value_1', 'value_2', 'value_3', 'note'];
        $rows[] = ['meta', 'generated_at', $data['generatedAt']->format('Y/m/d H:i:s'), '', '', ''];

        foreach ($data['summaryCards'] as $card) {
            $rows[] = ['summary', $card['label'], $card['value'], '', '', $card['note']];
        }

        foreach ($data['cityDistribution'] as $row) {
            $rows[] = ['city_distribution', $row['city'], $row['count'], $row['percent'], $row['recommended'], $row['is_initial'] ? 'initial_city' : ''];
        }

        foreach ($data['dungeonLosses'] as $row) {
            $rows[] = ['dungeon_losses', $row['name'], $row['count'], $row['rate'], $row['total'], $row['city']];
        }

        foreach ($data['dropOffPoints'] as $row) {
            $rows[] = ['drop_off_points', $row['name'], $row['count'], $row['percent'], '', '7日以上未ログイン'];
        }

        foreach ($data['popularJobs'] as $row) {
            $rows[] = ['popular_jobs', $row['name'], $row['count'], $row['percent'], '', ''];
        }

        foreach ($data['popularWeapons'] as $row) {
            $rows[] = ['popular_weapons', $row['name'], $row['count'], '', '', ''];
        }

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private function retention(Carbon $sevenDaysAgo): array
    {
        $eligibleUsers = User::where('created_at', '<=', $sevenDaysAgo)->count();

        if ($eligibleUsers <= 0) {
            return [
                'rate_label' => '-',
                'note' => '登録7日以上のユーザーなし',
            ];
        }

        $retainedUsers = User::where('created_at', '<=', $sevenDaysAgo)
            ->whereHas('characters', fn ($query) => $query->where('last_seen_at', '>=', $sevenDaysAgo))
            ->count();

        $rate = $retainedUsers / max(1, $eligibleUsers) * 100;

        return [
            'rate_label' => number_format($rate, 1) . '%',
            'note' => "登録7日以上 {$eligibleUsers}人中 {$retainedUsers}人",
        ];
    }

    private function changedJobCharacters(): int
    {
        if (!Schema::hasTable('character_jobs')) {
            return 0;
        }

        return DB::table('character_jobs')
            ->select('character_id')
            ->groupBy('character_id')
            ->havingRaw('COUNT(DISTINCT job_class_id) >= 2')
            ->get()
            ->count();
    }

    private function champChallengeCount(): int
    {
        if (!Schema::hasTable('champ_battle_logs')) {
            return 0;
        }

        return DB::table('champ_battle_logs')->count();
    }

    private function cityDistribution(int $totalCharacters): array
    {
        $cityIds = City::orderBy('sort_order')->orderBy('id')->pluck('id')->all();
        $rows = [];

        foreach (City::orderBy('sort_order')->orderBy('id')->get() as $city) {
            $count = Character::whereRaw('COALESCE(highest_city_id, current_city_id) = ?', [$city->id])->count();
            $rows[] = [
                'city' => $city->name,
                'count' => $count,
                'percent' => $this->percent($count, $totalCharacters),
                'recommended' => "Lv{$city->recommended_level_min}-{$city->recommended_level_max}",
                'is_initial' => (bool) $city->is_initial,
            ];
        }

        $unknownCount = Character::whereNotNull('id')
            ->where(function ($query) use ($cityIds) {
                $query->whereNull('highest_city_id')
                    ->whereNull('current_city_id');

                if (!empty($cityIds)) {
                    $query->orWhereNotIn(DB::raw('COALESCE(highest_city_id, current_city_id)'), $cityIds);
                }
            })
            ->count();

        if ($unknownCount > 0) {
            $rows[] = [
                'city' => '未設定',
                'count' => $unknownCount,
                'percent' => $this->percent($unknownCount, $totalCharacters),
                'recommended' => '-',
                'is_initial' => false,
            ];
        }

        return $rows;
    }

    private function dungeonLosses(): array
    {
        if (!Schema::hasTable('battle_logs')) {
            return [];
        }

        return DB::table('battle_logs')
            ->join('areas', 'battle_logs.area_id', '=', 'areas.id')
            ->leftJoin('cities', 'areas.city_id', '=', 'cities.id')
            ->selectRaw('areas.name as area_name, cities.name as city_name, COUNT(*) as total_count, SUM(CASE WHEN battle_logs.result = ? THEN 0 ELSE 1 END) as loss_count', ['win'])
            ->groupBy('areas.id', 'areas.name', 'cities.name')
            ->havingRaw('SUM(CASE WHEN battle_logs.result = ? THEN 0 ELSE 1 END) > 0', ['win'])
            ->orderByDesc('loss_count')
            ->orderByDesc('total_count')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'name' => $row->area_name,
                'city' => $row->city_name ?? '街不明',
                'count' => (int) $row->loss_count,
                'rate' => $this->percent((int) $row->loss_count, (int) $row->total_count),
                'total' => (int) $row->total_count,
            ])
            ->all();
    }

    private function popularJobs(int $totalCharacters): array
    {
        return DB::table('characters')
            ->leftJoin('job_classes', 'characters.current_job_id', '=', 'job_classes.id')
            ->selectRaw('COALESCE(job_classes.name, ?) as job_name, COUNT(*) as character_count', ['未設定'])
            ->groupBy('job_name')
            ->orderByDesc('character_count')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'name' => $row->job_name,
                'count' => (int) $row->character_count,
                'percent' => $this->percent((int) $row->character_count, $totalCharacters),
            ])
            ->all();
    }

    private function popularWeapons(): array
    {
        if (!Schema::hasTable('character_items')) {
            return [];
        }

        return DB::table('character_items')
            ->join('items', 'character_items.item_id', '=', 'items.id')
            ->where('character_items.is_equipped', true)
            ->where('items.type', 'weapon')
            ->selectRaw('items.name as item_name, COUNT(*) as equipped_count')
            ->groupBy('items.id', 'items.name')
            ->orderByDesc('equipped_count')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'name' => $row->item_name,
                'count' => (int) $row->equipped_count,
            ])
            ->all();
    }

    private function dropOffPoints(): array
    {
        $inactiveBorder = now()->subDays(7);
        $inactiveTotal = Character::where(function ($query) use ($inactiveBorder) {
            $query->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $inactiveBorder);
        })->count();

        if ($inactiveTotal <= 0) {
            return [];
        }

        return DB::table('characters')
            ->leftJoin('cities', DB::raw('COALESCE(characters.highest_city_id, characters.current_city_id)'), '=', 'cities.id')
            ->where(function ($query) use ($inactiveBorder) {
                $query->whereNull('characters.last_seen_at')->orWhere('characters.last_seen_at', '<', $inactiveBorder);
            })
            ->selectRaw('COALESCE(cities.name, ?) as city_name, COUNT(*) as inactive_count', ['未設定'])
            ->groupBy('city_name')
            ->orderByDesc('inactive_count')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'name' => $row->city_name,
                'count' => (int) $row->inactive_count,
                'percent' => $this->percent((int) $row->inactive_count, $inactiveTotal),
            ])
            ->all();
    }

    private function percent(int $value, int $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        return round($value / $total * 100, 1);
    }
}
