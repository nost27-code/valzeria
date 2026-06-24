<?php

namespace App\Livewire\Admin;

use App\Models\JobClass;
use App\Services\Battle\BattleTypeAffinity;
use Illuminate\Support\Collection;
use Livewire\Component;

class JobAffinityChecker extends Component
{
    public string $rankFilter = 'all';
    public string $search = '';

    private const RANK_LABELS = [
        'normal' => '一般',
        'middle' => '中級',
        'advanced' => '上級',
        'legend' => '伝説',
    ];

    private const STYLE_LABELS = [
        'physical' => '剛力',
        'speed' => '技巧',
        'magical' => '魔導',
    ];

    public function render()
    {
        $allJobs = JobClass::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $jobs = $allJobs
            ->when($this->rankFilter !== 'all', fn (Collection $items) => $items->where('rank', $this->rankFilter))
            ->when(trim($this->search) !== '', function (Collection $items) {
                $keyword = mb_strtolower(trim($this->search));

                return $items->filter(function (JobClass $job) use ($keyword) {
                    return str_contains(mb_strtolower($job->name), $keyword)
                        || str_contains(mb_strtolower($job->key ?? ''), $keyword)
                        || str_contains(mb_strtolower($job->category ?? ''), $keyword);
                });
            })
            ->values();

        return view('livewire.admin.job-affinity-checker', [
            'jobs' => $jobs,
            'rankLabels' => self::RANK_LABELS,
            'styleLabels' => self::STYLE_LABELS,
            'jobProfiles' => $jobs->mapWithKeys(fn (JobClass $job) => [$job->id => $this->jobProfile($job)])->all(),
            'diagnostics' => $this->diagnostics($allJobs),
        ])->layout('components.layouts.admin');
    }

    public function jobProfile(JobClass $job): array
    {
        $weights = $this->weights($job);
        arsort($weights);
        $dominantType = array_key_first($weights);
        $dominantValue = (float) ($weights[$dominantType] ?? 0.0);

        return [
            'weights' => $weights,
            'dominant_label' => self::STYLE_LABELS[$dominantType] ?? '剛力',
            'is_hybrid' => $dominantValue < 0.95,
            'attack_type' => $job->normal_attack_type === 'magical' ? '魔法' : '物理',
            'weight_text' => $this->weightText($weights),
        ];
    }

    public function affinityInfo(JobClass $attacker, JobClass $defender): array
    {
        $multiplier = BattleTypeAffinity::multiplier($this->weights($attacker), $this->weights($defender));
        $delta = (int) round(($multiplier - 1.0) * 100);
        $label = BattleTypeAffinity::label($multiplier);

        return [
            'multiplier' => $multiplier,
            'label' => $label,
            'delta' => $delta,
            'class' => $this->labelClass($label),
            'text' => $delta === 0 ? '±0%' : ($delta > 0 ? "+{$delta}%" : "{$delta}%"),
        ];
    }

    private function weights(JobClass $job): array
    {
        return BattleTypeAffinity::normalize([
            'physical' => (float) ($job->affinity_physical ?? 0.0),
            'speed' => (float) ($job->affinity_speed ?? 0.0),
            'magical' => (float) ($job->affinity_magical ?? 0.0),
        ]);
    }

    private function weightText(array $weights): string
    {
        $parts = [];
        foreach (self::STYLE_LABELS as $key => $label) {
            $value = (float) ($weights[$key] ?? 0.0);
            if ($value <= 0.0) {
                continue;
            }
            $parts[] = $label . number_format($value, 2);
        }

        return $parts ? implode(' / ', $parts) : '剛力1.00';
    }

    private function labelClass(string $label): string
    {
        return match ($label) {
            '有利' => 'bg-emerald-600 text-white',
            'やや有利' => 'bg-emerald-100 text-emerald-800',
            '不利' => 'bg-rose-600 text-white',
            'やや不利' => 'bg-rose-100 text-rose-800',
            default => 'bg-slate-100 text-slate-600',
        };
    }

    private function diagnostics(Collection $jobs): array
    {
        $items = [];
        $activeJobs = $jobs->where('is_active', true);
        $pureCounts = ['physical' => 0, 'speed' => 0, 'magical' => 0];
        $hybridCount = 0;

        foreach ($activeJobs as $job) {
            $weights = $this->weights($job);
            $maxValue = max($weights);
            $dominantType = array_search($maxValue, $weights, true);

            if ($maxValue >= 0.95) {
                $pureCounts[$dominantType] = ($pureCounts[$dominantType] ?? 0) + 1;
            } else {
                $hybridCount++;
            }
        }

        foreach (self::STYLE_LABELS as $key => $label) {
            if (($pureCounts[$key] ?? 0) === 0) {
                $items[] = [
                    'severity' => 'warning',
                    'title' => "純{$label}職がありません。",
                    'body' => '三すくみの基準点が弱くなり、相性差が見えづらくなる可能性があります。',
                ];
            }
        }

        if ($hybridCount > $activeJobs->count() * 0.6) {
            $items[] = [
                'severity' => 'info',
                'title' => 'ハイブリッド職が多めです。',
                'body' => '相性補正が穏やかになりやすい構成です。狙いどおりなら問題ありません。',
            ];
        }

        return $items;
    }
}
