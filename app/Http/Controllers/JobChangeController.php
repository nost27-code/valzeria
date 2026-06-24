<?php

namespace App\Http\Controllers;

use App\Models\JobClass;
use App\Services\CharacterJobChangeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class JobChangeController extends Controller
{
    protected CharacterJobChangeService $jobChangeService;

    public function __construct(CharacterJobChangeService $jobChangeService)
    {
        $this->jobChangeService = $jobChangeService;
    }

    public function index()
    {
        $character = Auth::user()->currentCharacter();
        $jobs = JobClass::where('is_active', true)->orderBy('sort_order')->get();

        return view('jobs.index', compact('character', 'jobs'));
    }

    public function confirm(JobClass $job)
    {
        $character = Auth::user()->currentCharacter();
        
        // 転職可能かどうかの事前チェック
        $check = $this->jobChangeService->canChangeJob($character, $job);
        if (!$check['success']) {
            return redirect()->route('jobs.index')->with('error', $check['message']);
        }

        $preview = $this->jobChangeService->previewJobChange($character, $job);

        return view('jobs.confirm', compact('character', 'job', 'preview'));
    }

    public function change(JobClass $job)
    {
        $character = Auth::user()->currentCharacter();
        
        $success = $this->jobChangeService->changeJob($character, $job);

        if ($success) {
            // 称号チェック
            $titleUnlockService = app(\App\Services\TitleUnlockService::class);
            $unlockedTitles = $titleUnlockService->checkJobTitles($character);

            // 転職完了画面に必要な情報をセッションに詰める（リダイレクト後表示のため）
            return redirect()->route('jobs.completed')->with([
                'success' => true,
                'newJob' => $job->name,
                'reincarnation_count' => $character->fresh()->reincarnation_count,
                'unlocked_titles' => $unlockedTitles,
                'unequip_messages' => $this->jobChangeService->getLastUnequipMessages(),
            ]);
        }

        return redirect()->route('jobs.index')->with('error', '転職処理に失敗しました。時間をおいて再度お試しください。');
    }

    public function completed()
    {
        if (!session('success')) {
            return redirect()->route('jobs.index');
        }

        return view('jobs.completed');
    }
}
