<?php

namespace App\Livewire;

use Livewire\Component;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\Auth;
use App\Services\CharacterStatusService;
use App\Services\LevelService;
use App\Services\ValmonService;

class LeftSidebar extends Component
{
    #[On('character-updated')]
    public function refresh()
    {
        // 状態更新時に再レンダーさせるためのフック
    }

    public function render(CharacterStatusService $statusService, LevelService $levelService, ValmonService $valmonService)
    {
        $character = null;
        $finalStats = null;
        $nextExp = 0;
        $jobLevel = 1;
        $jobExpInfo = null;
        $valmonNextLevelRemaining = null;
        $valmonIsMaxLevel = false;
        $valmonExpPercent = 0;

        if (Auth::check()) {
            $baseCharacter = Auth::user()->currentCharacter();
            if ($baseCharacter) {
                // リレーションをロードした状態の同じキャラクターを取得
                $baseCharacter->refresh();
                $character = $baseCharacter->load(['jobClass', 'jobHistories', 'characterItems.item', 'partnerValmon.master']);
                
                $finalStats = $statusService->getFinalStats($character);
                $nextExp = $levelService->getRequiredExp($character->level);

                $partnerValmon = $character->partnerValmon;
                if ($partnerValmon) {
                    $valmonIsMaxLevel = (int) $partnerValmon->level >= ValmonService::MAX_LEVEL;
                    $valmonNextLevelRemaining = $valmonService->nextLevelRemaining($partnerValmon);
                    $valmonNextRequired = (int) $partnerValmon->exp + (int) ($valmonNextLevelRemaining ?? 0);
                    $valmonExpPercent = $valmonIsMaxLevel
                        ? 100
                        : ($valmonNextRequired > 0 ? min(100, max(0, ((int) $partnerValmon->exp / $valmonNextRequired) * 100)) : 0);
                }

                $currentJobHistory = $character->jobHistories->where('job_class_id', $character->current_job_id)->first();
                if ($currentJobHistory) {
                    $jobLevel = $currentJobHistory->job_level;
                    $jobExpInfo = app(\App\Services\JobService::class)->getNextLevelExp($currentJobHistory);
                }

                $equippedTitle = app(\App\Services\TitleService::class)->getEquippedTitle($character);
            }
        }

        $allJobs = \App\Models\JobClass::all();

        return view('livewire.left-sidebar', [
            'character' => $character,
            'finalStats' => $finalStats,
            'nextExp' => $nextExp,
            'jobLevel' => $jobLevel,
            'jobExpInfo' => $jobExpInfo,
            'allJobs' => $allJobs,
            'equippedTitle' => $equippedTitle ?? null,
            'valmonNextLevelRemaining' => $valmonNextLevelRemaining,
            'valmonIsMaxLevel' => $valmonIsMaxLevel,
            'valmonExpPercent' => $valmonExpPercent,
        ]);
    }
}
