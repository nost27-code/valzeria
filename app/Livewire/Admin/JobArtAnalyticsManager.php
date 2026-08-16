<?php

namespace App\Livewire\Admin;

use App\Services\Admin\JobArtAnalyticsService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

class JobArtAnalyticsManager extends Component
{
    public string $battleContext = 'normal';

    public string $activityWindow = '30';

    public int $currentJobId = 0;

    public string $levelBand = 'all';

    public string $artSort = 'popular';

    public string $artSearch = '';

    public string $playerSearch = '';

    public int $playerPage = 1;

    public int $perPage = 25;

    public function mount(): void
    {
        $this->assertAdmin();
    }

    public function updated(string $property): void
    {
        if (in_array($property, [
            'battleContext',
            'activityWindow',
            'currentJobId',
            'levelBand',
            'playerSearch',
            'perPage',
        ], true)) {
            $this->playerPage = 1;
        }
    }

    public function resetFilters(): void
    {
        $this->battleContext = 'normal';
        $this->activityWindow = '30';
        $this->currentJobId = 0;
        $this->levelBand = 'all';
        $this->artSort = 'popular';
        $this->artSearch = '';
        $this->playerSearch = '';
        $this->playerPage = 1;
        $this->perPage = 25;
    }

    public function previousPlayerPage(): void
    {
        $this->playerPage = max(1, $this->playerPage - 1);
    }

    public function nextPlayerPage(int $lastPage): void
    {
        $this->playerPage = min(max(1, $lastPage), $this->playerPage + 1);
    }

    public function downloadCsv(): StreamedResponse
    {
        $this->assertAdmin();
        $filters = $this->filters();
        $filters['battle_context'] = in_array($filters['battle_context'], ['normal', 'boss', 'pvp'], true)
            ? $filters['battle_context']
            : 'normal';
        $rows = app(JobArtAnalyticsService::class)->exportPlayerRows($filters);
        $context = $filters['battle_context'];

        return response()->streamDownload(function () use ($rows, $context): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                '対象セット',
                'プレイヤー',
                'レベル',
                '現在職',
                '最終活動',
                'セット更新',
                '累計勝利',
                '累計敗北',
                '累計勝率',
                'SP方針',
                '1枠目',
                '2枠目',
                '3枠目',
                '4枠目',
                '5枠目',
            ]);

            foreach ($rows as $row) {
                $slots = collect($row['slots'])->keyBy('slot_no');
                fputcsv($out, [
                    $context,
                    $row['name'],
                    $row['level'],
                    $row['current_job_name'],
                    $row['last_seen_at'],
                    $row['set_updated_at'],
                    $row['wins'],
                    $row['losses'],
                    $row['win_rate'],
                    $row['sp_policy_label'],
                    ...collect(range(1, 5))
                        ->map(function (int $slotNo) use ($slots): string {
                            $slot = $slots->get($slotNo);
                            if (! $slot) {
                                return '';
                            }

                            return $slot['name'].($slot['is_active'] ? '' : '（無効保存）');
                        })
                        ->all(),
                ]);
            }

            fclose($out);
        }, 'valzeria-job-art-loadouts-'.$context.'-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function render()
    {
        $this->assertAdmin();
        $data = app(JobArtAnalyticsService::class)->analyze($this->filters());

        return view('livewire.admin.job-art-analytics-manager', $data)
            ->layout('components.layouts.admin');
    }

    /** @return array<string, mixed> */
    private function filters(): array
    {
        return [
            'battle_context' => $this->battleContext,
            'activity_window' => $this->activityWindow,
            'current_job_id' => $this->currentJobId,
            'level_band' => $this->levelBand,
            'art_sort' => $this->artSort,
            'art_search' => $this->artSearch,
            'player_search' => $this->playerSearch,
            'player_page' => $this->playerPage,
            'per_page' => $this->perPage,
        ];
    }

    private function assertAdmin(): void
    {
        abort_unless(Auth::check() && Auth::user()?->role === 'admin', 403);
    }
}
