<?php

namespace App\Services;

use App\Models\Character;
use App\Models\JobClass;
use App\Models\CharacterJob;
use App\Models\JobExpTable;
use App\Models\JobRequirement;

class JobService
{
    /**
     * 指定の職業に転職可能か判定する
     */
    public function canChangeJob(Character $character, JobClass $job): bool
    {
        // すでに転職条件をクリアしているかチェック
        // JobRequirementのレコードをループして判定する
        $requirements = $job->requirements;
        
        // 全職共通の最低レベル条件
        if ($character->level < 30) {
            return false;
        }

        if ($requirements->isEmpty()) {
            return $job->rank === 'normal'; // 条件がなければnormal職のみ転職可能
        }

        foreach ($requirements as $req) {
            if ($req->requirement_type === 'master_job') {
                $requiredJobId = $req->required_job_id;
                $hasMastered = $character->jobHistories()
                    ->where('job_class_id', $requiredJobId)
                    ->where('is_mastered', true)
                    ->exists();
                
                if (!$hasMastered) {
                    return false;
                }
            } elseif ($req->requirement_type === 'character_level') {
                if ($character->level < $req->required_value) {
                    return false;
                }
            }
            // その他の条件(称号、アイテム等)は将来拡張用
        }

        return true;
    }

    /**
     * 戦闘勝利時に職業経験値を付与し、レベルアップ・マスター判定を行う
     */
    public function addJobExp(Character $character, int $exp): array
    {
        $currentJobId = $character->current_job_id;
        if (!$currentJobId) {
            return ['level_up' => false, 'mastered' => false];
        }

        $characterJob = CharacterJob::firstOrCreate(
            ['character_id' => $character->id, 'job_class_id' => $currentJobId],
            ['job_level' => 1, 'job_exp' => 0]
        );

        if ($characterJob->is_mastered) {
            return ['level_up' => false, 'mastered' => false]; // 既にマスター済み
        }

        $jobClass = $characterJob->jobClass;
        $maxLevel = $jobClass->max_job_level;
        $oldLevel = $characterJob->job_level; // 追加: 以前のレベルを保持
        
        $characterJob->job_exp += $exp;
        
        $levelUp = false;
        $mastered = false;

        // 職業ランクアップループ
        while ($characterJob->job_level < $maxLevel) {
            $nextLevel = $characterJob->job_level + 1;
            
            // 次のランクの必要経験値を取得
            $expTable = JobExpTable::where('job_level', $nextLevel)->first();
            if (!$expTable) {
                break; // 経験値テーブルがない場合はループ終了
            }

            // 職業ランク（必要経験値補正）を計算（仕様書による目安）
            // rank = normal: 1.0, middle: 2.0, advanced: 4.0, legend: 10.0
            $multiplier = 1.0;
            switch ($jobClass->rank) {
                case 'middle': $multiplier = 2.0; break;
                case 'advanced': $multiplier = 4.0; break;
                case 'legend': $multiplier = 10.0; break;
            }

            $requiredExp = (int)($expTable->required_exp * $multiplier);

            if ($characterJob->job_exp >= $requiredExp) {
                $characterJob->job_level = $nextLevel;
                $levelUp = true;
            } else {
                break; // 経験値が足りないのでループ終了
            }
        }

        // マスター判定
        if ($characterJob->job_level >= $maxLevel) {
            $characterJob->is_mastered = true;
            $characterJob->mastered_at = now();
            $mastered = true;
        }

        $characterJob->save();

        return [
            'level_up' => $levelUp,
            'mastered' => $mastered,
            'old_level' => $oldLevel, // 追加
            'job_level' => $characterJob->job_level,
            'job_name' => $jobClass->name,
        ];
    }

    /**
     * 次のランクまでの必要経験値を取得する
     * @return array ['current' => 現在の経験値, 'next_required' => 次のランクに必要な総経験値, 'is_mastered' => マスター済みか]
     */
    public function getNextLevelExp(CharacterJob $characterJob): array
    {
        if ($characterJob->is_mastered || $characterJob->job_level >= $characterJob->jobClass->max_job_level) {
            return [
                'current' => $characterJob->job_exp,
                'next_required' => $characterJob->job_exp, // マスター時は同じ値
                'is_mastered' => true
            ];
        }

        $nextLevel = $characterJob->job_level + 1;
        $expTable = JobExpTable::where('job_level', $nextLevel)->first();
        if (!$expTable) {
            return [
                'current' => $characterJob->job_exp,
                'next_required' => $characterJob->job_exp,
                'is_mastered' => true
            ];
        }

        $multiplier = 1.0;
        switch ($characterJob->jobClass->rank) {
            case 'middle': $multiplier = 2.0; break;
            case 'advanced': $multiplier = 4.0; break;
            case 'legend': $multiplier = 10.0; break;
        }

        $requiredExp = (int)($expTable->required_exp * $multiplier);

        return [
            'current' => $characterJob->job_exp,
            'next_required' => $requiredExp,
            'is_mastered' => false
        ];
    }

    /**
     * マスターボーナスの合計値を計算する
     */
    public function calculateMasterBonuses(Character $character): array
    {
        $bonuses = [
            'hp_rate' => 0, 'mp_rate' => 0, 'atk_rate' => 0, 'def_rate' => 0,
            'mag_rate' => 0, 'spr_rate' => 0, 'spd_rate' => 0, 'luck_rate' => 0,
        ];

        // マスター済みの職業IDリストを取得
        $masteredJobIds = $character->jobHistories()->where('is_mastered', true)->pluck('job_class_id');

        if ($masteredJobIds->isEmpty()) {
            return $bonuses;
        }

        // マスターボーナスを集計
        $masterBonuses = \App\Models\JobMasterBonus::whereIn('job_id', $masteredJobIds)->get();

        foreach ($masterBonuses as $bonus) {
            if (isset($bonuses[$bonus->bonus_type])) {
                $bonuses[$bonus->bonus_type] += $bonus->bonus_value;
            }
        }

        return $bonuses;
    }

    /**
     * 最終ステータス（現在職補正 ＋ マスターボーナス）を計算
     */
    public function calculateFinalStats(Character $character): array
    {
        $baseStats = [
            'hp' => $character->hp_base,
            'mp' => $character->mp_base,
            'atk' => $character->attack_base,
            'def' => $character->defense_base,
            'mag' => $character->magic_base,
            'spr' => $character->spirit_base,
            'spd' => $character->speed_base,
            'luck' => $character->luck_base,
        ];

        // マスター済み職業のIDを取得し、永続ボーナスとして加算する
        // ※ 現在職（current_job_id）は除外する。
        //   現在職の bonus_* は CharacterStatusService で「職業Lvボーナス (bonus_* × jobLevel × 0.5)」として加算されるため、
        //   ここで加算すると二重カウントになる。
        $currentJobId = $character->current_job_id;
        $masteredJobIds = $character->jobHistories()
            ->where('is_mastered', true)
            ->when($currentJobId, fn($q) => $q->where('job_class_id', '!=', $currentJobId))
            ->pluck('job_class_id');
        
        if ($masteredJobIds->isNotEmpty()) {
            $masteredJobs = JobClass::whereIn('id', $masteredJobIds)->get();
            foreach ($masteredJobs as $job) {
                $baseStats['hp'] += $job->bonus_hp ?? 0;
                $baseStats['mp'] += $job->bonus_mp ?? 0;
                $baseStats['atk'] += $job->bonus_str ?? 0;
                $baseStats['def'] += $job->bonus_def ?? 0;
                $baseStats['mag'] += $job->bonus_mag ?? 0;
                $baseStats['spr'] += $job->bonus_spr ?? 0;
                $baseStats['spd'] += $job->bonus_spd ?? 0;
                $baseStats['luck'] += $job->bonus_luk ?? 0;
            }
        }

        return $baseStats;
    }
}
