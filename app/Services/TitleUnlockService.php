<?php

namespace App\Services;

use App\Models\Character;
use App\Models\Title;
use App\Models\Area;
use App\Models\City;
use App\Models\JobClass;
use Illuminate\Support\Facades\Log;

class TitleUnlockService
{
    protected TitleService $titleService;

    public function __construct(TitleService $titleService)
    {
        $this->titleService = $titleService;
    }

    /**
     * 特定のキャラクターについて、すべての獲得可能な称号をチェックして付与する
     */
    public function checkAllUnlocks(Character $character): array
    {
        $unlockedTitles = [];
        $unlockedTitles = array_merge($unlockedTitles, $this->checkBattleTitles($character));
        $unlockedTitles = array_merge($unlockedTitles, $this->checkAreaClearTitles($character));
        $unlockedTitles = array_merge($unlockedTitles, $this->checkJobTitles($character));

        return $unlockedTitles;
    }

    /**
     * 戦闘系（戦闘回数、ボス撃破回数）の称号をチェック
     */
    public function checkBattleTitles(Character $character): array
    {
        $unlockedTitles = [];
        $ownedTitleIds = $character->titles()->pluck('title_id')->toArray();

        // count系の称号を取得
        $titles = Title::where('target_type', 'count')->get();

        foreach ($titles as $title) {
            if (in_array($title->id, $ownedTitleIds)) {
                continue;
            }

            $shouldUnlock = false;
            $targetCount = (int)$title->target_id;

            if ($title->unlock_type === 'battle_win_count') {
                // 戦闘勝利回数は wins プロパティを使用する
                $wins = $character->wins ?? 0;
                if ($wins >= $targetCount) {
                    $shouldUnlock = true;
                }
            } elseif ($title->unlock_type === 'boss_clear_count') {
                // ボス撃破数は area_progresses の boss_defeated = true の数を数える
                $bossDefeatedCount = $character->areaProgresses()->where('boss_defeated', true)->count();
                if ($bossDefeatedCount >= $targetCount) {
                    $shouldUnlock = true;
                }
            }

            if ($shouldUnlock) {
                $this->titleService->unlockTitle($character, $title->id);
                $unlockedTitles[] = $title;
                $ownedTitleIds[] = $title->id;
            }
        }

        return $unlockedTitles;
    }

    /**
     * ダンジョンクリア系、街クリア系、ワールドクリア系の称号をチェック
     */
    public function checkAreaClearTitles(Character $character): array
    {
        $unlockedTitles = [];
        $ownedTitleIds = $character->titles()->pluck('title_id')->toArray();

        $titles = Title::whereIn('target_type', ['dungeon', 'city', 'world'])->get();

        // 事前にクリア済みエリアIDの配列を取得
        $clearedAreaIds = $character->areaProgresses()->where('boss_defeated', true)->pluck('area_id')->toArray();

        foreach ($titles as $title) {
            if (in_array($title->id, $ownedTitleIds)) {
                continue;
            }

            $shouldUnlock = false;

            if ($title->target_type === 'dungeon' && $title->unlock_type === 'dungeon_boss_clear') {
                // 特定のダンジョンのボス撃破
                $targetAreaId = (int)$title->target_id;
                if (in_array($targetAreaId, $clearedAreaIds)) {
                    $shouldUnlock = true;
                }
            } elseif ($title->target_type === 'city' && $title->unlock_type === 'city_all_dungeons_clear') {
                // 特定の街の全ダンジョン制覇
                $targetCityId = (int)$title->target_id;
                // その街に属するエリアのID一覧
                $cityAreaIds = Area::where('city_id', $targetCityId)->pluck('id')->toArray();
                
                // cityAreaIds が全て clearedAreaIds に含まれているか
                if (!empty($cityAreaIds)) {
                    $isAllCleared = true;
                    foreach ($cityAreaIds as $areaId) {
                        if (!in_array($areaId, $clearedAreaIds)) {
                            $isAllCleared = false;
                            break;
                        }
                    }
                    if ($isAllCleared) {
                        $shouldUnlock = true;
                    }
                }
            } elseif ($title->target_type === 'world' && $title->unlock_type === 'all_dungeons_clear') {
                // 全ダンジョン制覇
                $allAreaIds = Area::pluck('id')->toArray();
                if (!empty($allAreaIds)) {
                    $isAllCleared = true;
                    foreach ($allAreaIds as $areaId) {
                        if (!in_array($areaId, $clearedAreaIds)) {
                            $isAllCleared = false;
                            break;
                        }
                    }
                    if ($isAllCleared) {
                        $shouldUnlock = true;
                    }
                }
            }

            if ($shouldUnlock) {
                $this->titleService->unlockTitle($character, $title->id);
                $unlockedTitles[] = $title;
                $ownedTitleIds[] = $title->id;
            }
        }

        return $unlockedTitles;
    }

    /**
     * 職業系（特定職業マスター、上級職転職、全職マスター等）の称号をチェック
     */
    public function checkJobTitles(Character $character): array
    {
        $unlockedTitles = [];
        $ownedTitleIds = $character->titles()->pluck('title_id')->toArray();

        $titles = Title::whereIn('target_type', ['job_name', 'rank', 'count', 'all_jobs'])->get();

        // 転職履歴・マスター状況を取得
        $jobHistories = $character->jobHistories()->with('jobClass')->get();
        $masteredJobNames = [];
        $experiencedRanks = [];
        $masteredCount = 0;

        foreach ($jobHistories as $jh) {
            $jobClass = $jh->jobClass;
            if (!$jobClass) continue;

            $experiencedRanks[] = $jobClass->rank;
            
            // max_levelに達していればマスターとする
            if ($jh->job_level >= $jobClass->max_level) {
                $masteredJobNames[] = $jobClass->name;
                $masteredCount++;
            }
        }

        $allJobsCount = JobClass::count();

        foreach ($titles as $title) {
            if (in_array($title->id, $ownedTitleIds)) {
                continue;
            }

            $shouldUnlock = false;

            if ($title->unlock_type === 'job_master' && $title->target_type === 'job_name') {
                // 特定職業をマスター
                if (in_array($title->target_id, $masteredJobNames)) {
                    $shouldUnlock = true;
                }
            } elseif ($title->unlock_type === 'first_rank_job' && $title->target_type === 'rank') {
                // 特定ランクの職に転職したか
                if (in_array($title->target_id, $experiencedRanks)) {
                    $shouldUnlock = true;
                }
            } elseif ($title->unlock_type === 'job_master_count' && $title->target_type === 'count') {
                // マスターした職業の数
                $targetCount = (int)$title->target_id;
                if ($masteredCount >= $targetCount) {
                    $shouldUnlock = true;
                }
            } elseif ($title->unlock_type === 'all_jobs_master' && $title->target_type === 'all_jobs') {
                // 全職業マスター
                if ($masteredCount >= $allJobsCount && $allJobsCount > 0) {
                    $shouldUnlock = true;
                }
            }

            if ($shouldUnlock) {
                $this->titleService->unlockTitle($character, $title->id);
                $unlockedTitles[] = $title;
                $ownedTitleIds[] = $title->id;
            }
        }

        return $unlockedTitles;
    }
}
