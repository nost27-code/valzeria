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
        $allJobs = JobClass::with('masterBonuses')
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

    public function render()
    {
        return view('livewire.job-change');
    }
}
