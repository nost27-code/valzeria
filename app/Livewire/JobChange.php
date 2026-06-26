<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Character;
use App\Models\JobClass;
use App\Models\CharacterJob;
use App\Services\JobService;
use App\Services\CharacterJobChangeService;
use App\Services\PublicLogService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;

#[Layout('components.layouts.facility', ['title' => '神殿', 'headerIconImage' => 'images/facilities/facility_temple.webp', 'bgImage' => 'images/bg-castle.webp'])]
class JobChange extends Component
{
    public $character;
    public $availableJobs = [];
    public $unavailableJobs = [];
    public $selectedJobId = null;
    public $expInfo = [];
    public $jobProgress = [];
    
    // 転職確認モーダル用
    public $confirmingJobChange = false;
    public $selectedJob = null;
    public $statPreview = [];
    public bool $showingJobDetail = false;
    public ?int $detailJobId = null;
    public $detailJob = null;
    public array $detailJobGrowthStats = [];
    public array $detailJobMasterBonusChips = [];
    public bool $detailJobCanChange = false;

    public function mount()
    {
        $this->character = Auth::user()->characters()->first();
        if (!$this->character) {
            return redirect()->route('home');
        }
        
        $jobService = new JobService();
        $charJob = $this->character->jobHistories()->where('job_class_id', $this->character->current_job_id)->first();
        if ($charJob) {
            $this->expInfo = $jobService->getNextLevelExp($charJob);
        } else {
            $this->expInfo = ['current' => 0, 'next_required' => 0, 'is_mastered' => false];
        }
        
        $this->loadJobs();
    }

    public function loadJobs()
    {
        $jobService = new JobService();
        $allJobs = JobClass::with(['masterBonuses', 'requirements.requiredJob', 'jobArts'])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
        $this->jobProgress = $this->character->jobHistories()
            ->get()
            ->keyBy('job_class_id')
            ->map(fn($jobHistory) => [
                'level' => (int) $jobHistory->job_level,
                'is_mastered' => (bool) $jobHistory->is_mastered || (int) $jobHistory->job_level >= 10,
            ])
            ->toArray();
        
        $this->availableJobs = [];
        $this->unavailableJobs = [];

        foreach ($allJobs as $job) {
            // 現在の職業は除外
            if ($this->character->current_job_id === $job->id) {
                continue;
            }

            if ($jobService->canChangeJob($this->character, $job)) {
                $this->availableJobs[] = $job;
            } else {
                // 隠し職業でなければ未達成リストに追加
                if (!$job->is_hidden) {
                    $this->unavailableJobs[] = clone $job;
                }
            }
        }
    }

    public function confirmJobChange($jobId)
    {
        $this->closeJobDetail();

        $this->selectedJobId = $jobId;
        $this->selectedJob = collect($this->availableJobs)->firstWhere('id', $jobId);
        
        if (!$this->selectedJob) {
            return;
        }

        // 転職後のステータス予測をサービスから取得
        $jobChangeService = new CharacterJobChangeService(new PublicLogService());
        $preview = $jobChangeService->previewJobChange($this->character, $this->selectedJob);
        
        // Blade側で表示できるようにアサイン
        $this->statPreview = $preview['after'];

        $this->confirmingJobChange = true;
    }

    public function changeJob()
    {
        if (!$this->selectedJobId || !$this->selectedJob) {
            return;
        }

        $jobChangeService = new CharacterJobChangeService(new PublicLogService());
        $success = $jobChangeService->changeJob($this->character, $this->selectedJob);

        if ($success) {
            $unequipMessages = $jobChangeService->getLastUnequipMessages();
            // character_jobs が無ければ作成
            CharacterJob::firstOrCreate(
                ['character_id' => $this->character->id, 'job_class_id' => $this->selectedJobId],
                ['job_level' => 1, 'job_exp' => 0]
            );

            $jobService = new JobService();
            $charJob = $this->character->jobHistories()->where('job_class_id', $this->selectedJobId)->first();
            if ($charJob) {
                $this->expInfo = $jobService->getNextLevelExp($charJob);
            }

            $this->confirmingJobChange = false;
            
            $message = '職業を「' . $this->selectedJob->name . '」に変更しました！';
            if ($unequipMessages) {
                $message .= ' ' . implode(' ', $unequipMessages);
            }
            session()->flash('message', $message);
        } else {
            session()->flash('message', '転職処理に失敗しました。条件を満たしているか確認してください。');
        }

        $this->selectedJobId = null;
        $this->selectedJob = null;
        
        $this->loadJobs();
    }

    public function showJobDetail(int $jobId): void
    {
        $job = JobClass::with(['jobArts', 'masterBonuses', 'requirements.requiredJob'])
            ->where('is_active', true)
            ->find($jobId);

        if (! $job) {
            return;
        }

        $jobService = new JobService();
        $canChange = $jobService->canChangeJob($this->character, $job);

        if ($job->is_hidden && ! $canChange) {
            return;
        }

        $this->detailJobId = (int) $job->id;
        $this->detailJob = $job;
        $this->detailJobCanChange = (bool) $canChange;
        $this->detailJobGrowthStats = $this->buildGrowthStats($job);
        $this->detailJobMasterBonusChips = $this->buildMasterBonusChips($job);
        $this->showingJobDetail = true;
    }

    public function closeJobDetail(): void
    {
        $this->showingJobDetail = false;
        $this->detailJobId = null;
        $this->detailJob = null;
        $this->detailJobGrowthStats = [];
        $this->detailJobMasterBonusChips = [];
        $this->detailJobCanChange = false;
    }

    public function confirmJobChangeFromDetail(): void
    {
        if (! $this->detailJob || ! $this->detailJobCanChange) {
            return;
        }

        $jobId = (int) $this->detailJob->id;

        $this->closeJobDetail();
        $this->confirmJobChange($jobId);
    }

    private function buildGrowthStats(JobClass $job): array
    {
        $stats = [
            'hp' => ['label' => 'HP', 'min' => (int) ($job->hp_growth_min ?? 0), 'max' => (int) ($job->hp_growth_max ?? 0)],
            'attack' => ['label' => '攻撃', 'min' => (int) ($job->attack_growth_min ?? 0), 'max' => (int) ($job->attack_growth_max ?? 0)],
            'defense' => ['label' => '防御', 'min' => (int) ($job->defense_growth_min ?? 0), 'max' => (int) ($job->defense_growth_max ?? 0)],
            'magic' => ['label' => '魔力', 'min' => (int) ($job->magic_growth_min ?? 0), 'max' => (int) ($job->magic_growth_max ?? 0)],
            'speed' => ['label' => '敏捷', 'min' => (int) ($job->speed_growth_min ?? 0), 'max' => (int) ($job->speed_growth_max ?? 0)],
            'luck' => ['label' => '運', 'min' => (int) ($job->luck_growth_min ?? 0), 'max' => (int) ($job->luck_growth_max ?? 0)],
        ];

        if (isset($job->spirit_growth_min) || isset($job->spirit_growth_max)) {
            $stats['spirit'] = ['label' => '精神', 'min' => (int) ($job->spirit_growth_min ?? 0), 'max' => (int) ($job->spirit_growth_max ?? 0)];
        }

        foreach ($stats as $key => $stat) {
            $stats[$key]['avg'] = ($stat['min'] + $stat['max']) / 2;
        }

        return collect($stats)
            ->filter(fn (array $stat) => $stat['avg'] > 0)
            ->sortByDesc('avg')
            ->take(3)
            ->values()
            ->toArray();
    }

    private function buildMasterBonusChips(JobClass $job): array
    {
        $chips = [];
        $fields = [
            'bonus_hp' => ['HP', ''],
            'bonus_mp' => ['SP', ''],
            'bonus_str' => ['攻撃', ''],
            'bonus_def' => ['防御', ''],
            'bonus_mag' => ['魔力', ''],
            'bonus_spr' => ['精神', ''],
            'bonus_spd' => ['敏捷', ''],
            'bonus_luk' => ['運', ''],
            'bonus_drop_rate' => ['ドロップ', '%'],
            'bonus_critical_rate' => ['必殺', '%'],
        ];

        foreach ($fields as $field => [$label, $suffix]) {
            $value = (int) ($job->{$field} ?? 0);
            if ($value !== 0) {
                $chips[] = ['label' => $label, 'value' => $value, 'suffix' => $suffix];
            }
        }

        if ($job->relationLoaded('masterBonuses')) {
            $labels = [
                'hp_rate' => ['HP', '%'],
                'mp_rate' => ['SP', '%'],
                'atk_rate' => ['攻撃', '%'],
                'def_rate' => ['防御', '%'],
                'mag_rate' => ['魔力', '%'],
                'spr_rate' => ['精神', '%'],
                'spd_rate' => ['敏捷', '%'],
                'luck_rate' => ['運', '%'],
                'drop_rate' => ['ドロップ', '%'],
                'critical_rate' => ['必殺', '%'],
                'evasion_rate' => ['回避', '%'],
                'heal_rate' => ['回復', '%'],
                'item_effect_rate' => ['道具効果', '%'],
            ];

            foreach ($job->masterBonuses as $bonus) {
                $value = (int) $bonus->bonus_value;
                if ($value === 0) {
                    continue;
                }

                [$label, $suffix] = $labels[$bonus->bonus_type] ?? [$bonus->bonus_type, '%'];
                $chips[] = ['label' => $label, 'value' => $value, 'suffix' => $suffix];
            }
        }

        return $chips;
    }

    public function render()
    {
        return view('livewire.job-change');
    }
}
