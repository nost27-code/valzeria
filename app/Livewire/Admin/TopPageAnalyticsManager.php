<?php

namespace App\Livewire\Admin;

use App\Models\TopPageEvent;
use App\Models\TopPageVisit;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class TopPageAnalyticsManager extends Component
{
    public int $days = 7;

    public function setDays(int $days): void
    {
        $this->days = in_array($days, [1, 7, 30, 90], true) ? $days : 7;
    }

    public function render()
    {
        $since = now()->subDays($this->days - 1)->startOfDay();

        $visits = TopPageVisit::query()->where('visited_at', '>=', $since);
        $events = TopPageEvent::query()->where('occurred_at', '>=', $since);

        $visitCount = (clone $visits)->count();
        $uniqueVisitorCount = (clone $visits)->whereNotNull('ip_hash')->distinct('ip_hash')->count('ip_hash');
        $avgDuration = (int) round((float) (clone $visits)
            ->whereNotNull('duration_seconds')
            ->avg('duration_seconds'));

        return view('livewire.admin.top-page-analytics-manager', [
            'summaryCards' => [
                ['label' => 'TOP訪問数', 'value' => number_format($visitCount), 'unit' => '件'],
                ['label' => '推定ユニーク', 'value' => number_format($uniqueVisitorCount), 'unit' => '人'],
                ['label' => '平均滞在時間', 'value' => $this->durationLabel($avgDuration), 'unit' => ''],
                ['label' => '登録導線クリック', 'value' => number_format($this->registrationClickCount($since)), 'unit' => '回'],
            ],
            'ctaCounts' => $this->ctaCounts($since),
            'refererRows' => $this->refererRows($since),
            'deviceRows' => $this->deviceRows($since),
            'dailyRows' => $this->dailyRows($since),
            'recentEvents' => TopPageEvent::query()
                ->with('visit')
                ->latest('occurred_at')
                ->limit(30)
                ->get(),
        ])->layout('components.layouts.admin');
    }

    private function registrationClickCount($since): int
    {
        return TopPageEvent::query()
            ->where('occurred_at', '>=', $since)
            ->whereIn('event_name', ['google_start_click', 'email_register_click', 'guest_start_click'])
            ->count();
    }

    private function ctaCounts($since)
    {
        return TopPageEvent::query()
            ->select('event_name', DB::raw('COUNT(*) as total'))
            ->where('occurred_at', '>=', $since)
            ->whereIn('event_name', [
                'google_start_click',
                'google_login_click',
                'email_register_click',
                'email_login_click',
                'guest_start_click',
            ])
            ->groupBy('event_name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'label' => $this->eventLabel((string) $row->event_name),
                'event_name' => (string) $row->event_name,
                'total' => (int) $row->total,
            ]);
    }

    private function refererRows($since)
    {
        return TopPageVisit::query()
            ->select('referer_host', DB::raw('COUNT(*) as total'))
            ->where('visited_at', '>=', $since)
            ->groupBy('referer_host')
            ->orderByDesc('total')
            ->limit(12)
            ->get()
            ->map(fn ($row) => [
                'label' => $row->referer_host ?: '直接/不明',
                'total' => (int) $row->total,
            ]);
    }

    private function deviceRows($since)
    {
        return TopPageVisit::query()
            ->select('device_type', DB::raw('COUNT(*) as total'))
            ->where('visited_at', '>=', $since)
            ->groupBy('device_type')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'label' => match ((string) $row->device_type) {
                    'mobile' => 'スマホ',
                    'tablet' => 'タブレット',
                    'desktop' => 'PC',
                    default => '不明',
                },
                'total' => (int) $row->total,
            ]);
    }

    private function dailyRows($since): array
    {
        $rows = TopPageVisit::query()
            ->selectRaw('DATE(visited_at) as day, COUNT(*) as total')
            ->where('visited_at', '>=', $since)
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('total', 'day');

        $days = [];
        for ($date = $since->copy(); $date->lte(now()); $date->addDay()) {
            $key = $date->format('Y-m-d');
            $days[] = [
                'day' => $date->format('m/d'),
                'total' => (int) ($rows[$key] ?? 0),
            ];
        }

        return $days;
    }

    private function eventLabel(string $eventName): string
    {
        return [
            'google_start_click' => 'Googleで冒険開始',
            'google_login_click' => 'Googleログイン',
            'email_register_click' => 'メール新規登録',
            'email_login_click' => 'メールログイン',
            'guest_start_click' => 'ゲスト開始',
            'page_dwell' => '滞在時間送信',
        ][$eventName] ?? $eventName;
    }

    private function durationLabel(int $seconds): string
    {
        if ($seconds <= 0) {
            return '-';
        }

        $minutes = intdiv($seconds, 60);
        $remainingSeconds = $seconds % 60;

        return $minutes > 0 ? "{$minutes}分{$remainingSeconds}秒" : "{$remainingSeconds}秒";
    }
}
