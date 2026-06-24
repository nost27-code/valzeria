<?php

namespace App\Services;

use App\Models\Character;
use Illuminate\Support\Facades\Log;
use App\Services\CharacterStatusService;

class LevelService
{
    private const GROWTH_MULTIPLIER = 1.12;
    private const BONUS_POINTS_PER_LEVEL = 1;

    /**
     * 報酬（EXPなど）を付与し、レベルアップ処理を行う
     * 
     * @return array 獲得した結果やレベルアップ内容を含む連想配列
     */
    public function addRewardAndCheckLevelUp(Character $character, int $expGained, int $goldGained, int $jobExpGained = 0): array
    {
        if ($goldGained > 0) {
            app(GoldService::class)->add($character, $goldGained, 'battle_reward', '戦闘でGoldを獲得');
        }

        // レベル255以上なら経験値は増えない
        if ($character->level < 255) {
            $character->exp += $expGained;
        }

        $levelUpCount = 0;
        $levelUpDetails = [];
        
        $jobResult = null;
        if ($jobExpGained > 0) {
            $jobResult = app(JobService::class)->addJobExp($character, $jobExpGained);
        }

        // レベルアップループ
        while ($character->exp >= $this->getRequiredExp($character->level)) {
            $requiredExp = $this->getRequiredExp($character->level);
            
            // 経験値を消費（または累積として扱うなら消費しない。仕様書：「必要EXP分を消費」）
            $character->exp -= $requiredExp;
            $character->level += 1;
            $levelUpCount++;

            // 職業のステータス倍率を取得（未設定時は1.0倍）
            $job = $character->jobClass;
            $hpRate = $job ? ($job->hp_rate / 100) : 1.0;
            $mpRate = $job ? ($job->mp_rate / 100) : 1.0;
            $strRate = $job ? ($job->atk_rate / 100) : 1.0;
            $defRate = $job ? ($job->def_rate / 100) : 1.0;
            $agiRate = $job ? ($job->spd_rate / 100) : 1.0;
            $magRate = $job ? ($job->mag_rate / 100) : 1.0;
            $sprRate = $job ? ($job->spr_rate / 100) : 1.0;
            $lukRate = $job ? ($job->luck_rate / 100) : 1.0;

            // 転生回数補正係数の計算
            $reincarnationCount = $character->reincarnation_count ?? 0;
            $reincarnationBonus = 1 + 0.03 * sqrt($reincarnationCount);

            // 確定成長値の計算（期待値 * 職業倍率 * 転生補正）
            $hpUpRaw = 8.25 * self::GROWTH_MULTIPLIER * $hpRate * $reincarnationBonus;
            $mpUpRaw = 4.95 * self::GROWTH_MULTIPLIER * $mpRate * $reincarnationBonus;
            $strUpRaw = 3.85 * self::GROWTH_MULTIPLIER * $strRate * $reincarnationBonus;
            $defUpRaw = 3.85 * self::GROWTH_MULTIPLIER * $defRate * $reincarnationBonus;
            $agiUpRaw = 3.85 * self::GROWTH_MULTIPLIER * $agiRate * $reincarnationBonus;
            $magUpRaw = 3.85 * self::GROWTH_MULTIPLIER * $magRate * $reincarnationBonus;
            $sprUpRaw = 3.85 * self::GROWTH_MULTIPLIER * $sprRate * $reincarnationBonus;
            $lukUpRaw = 3.85 * self::GROWTH_MULTIPLIER * $lukRate * $reincarnationBonus;

            // 端数を加算
            $totalHp = $character->hp_fraction + $hpUpRaw;
            $totalMp = $character->mp_fraction + $mpUpRaw;
            $totalStr = $character->attack_fraction + $strUpRaw;
            $totalDef = $character->defense_fraction + $defUpRaw;
            $totalAgi = $character->speed_fraction + $agiUpRaw;
            $totalMag = $character->magic_fraction + $magUpRaw;
            $totalSpr = $character->spirit_fraction + $sprUpRaw;
            $totalLuk = $character->luck_fraction + $lukUpRaw;

            // 実際に上昇する値（整数部）
            $hpUp = (int)floor($totalHp);
            $mpUp = (int)floor($totalMp);
            $strUp = (int)floor($totalStr);
            $defUp = (int)floor($totalDef);
            $agiUp = (int)floor($totalAgi);
            $magUp = (int)floor($totalMag);
            $sprUp = (int)floor($totalSpr);
            $lukUp = (int)floor($totalLuk);

            // 残りの端数をストック
            $character->hp_fraction = $totalHp - $hpUp;
            $character->mp_fraction = $totalMp - $mpUp;
            $character->attack_fraction = $totalStr - $strUp;
            $character->defense_fraction = $totalDef - $defUp;
            $character->speed_fraction = $totalAgi - $agiUp;
            $character->magic_fraction = $totalMag - $magUp;
            $character->spirit_fraction = $totalSpr - $sprUp;
            $character->luck_fraction = $totalLuk - $lukUp;

            $character->hp_base += $hpUp;
            $character->mp_base += $mpUp;
            $character->attack_base += $strUp;
            $character->defense_base += $defUp;
            $character->speed_base += $agiUp;
            $character->magic_base += $magUp;
            $character->spirit_base += $sprUp;
            $character->luck_base += $lukUp;
            $character->bonus_points = (int) ($character->bonus_points ?? 0) + self::BONUS_POINTS_PER_LEVEL;

            // 一旦保存しないとステータス計算に最新のbase値が反映されない可能性があるが、
            // getFinalStats内で引数の$characterのプロパティを読んでいるため、
            // 保存前でも更新されたプロパティベースで計算される
            $statusService = app(CharacterStatusService::class);
            $finalStats = $statusService->getFinalStats($character);

            // レベルアップ時はHP/SPを回復しない（最大値の上限のみクランプ）
            $maxHp = $finalStats['max_hp'] ?? $character->hp_base;
            $maxMp = $finalStats['max_mp'] ?? $character->mp_base;
            $character->current_hp = min($character->current_hp, $maxHp);
            $character->current_mp = min($character->current_mp, $maxMp);

            $levelUpDetails[] = [
                'level' => $character->level,
                'hp_up' => $hpUp,
                'mp_up' => $mpUp,
                'str_up' => $strUp,
                'def_up' => $defUp,
                'agi_up' => $agiUp,
                'mag_up' => $magUp,
                'spr_up' => $sprUp,
                'luk_up' => $lukUp,
                'bonus_points' => self::BONUS_POINTS_PER_LEVEL,
            ];
            
            // 節目レベルで公開ログ（Lv10は序盤で頻出するため流さない）
            if (in_array($character->level, [50, 100])) {
                app(PublicLogService::class)->addLog(
                    'growth',
                    "【成長】{$character->name}さんがLv{$character->level}に到達しました！",
                    $character
                );
            }

            // Lv255に達したらレベルアップループを強制終了
            if ($character->level >= 255) {
                // 経験値超過分をリセット（上限到達時）
                $character->exp = 0; // または $this->getRequiredExp(255) でカンストさせる等。今回は0に戻してストップでOK（増えないため）
                break;
            }
        }

        $character->save();

        return [
            'level_up_count' => $levelUpCount,
            'details' => $levelUpDetails,
            'job_result' => $jobResult,
        ];
    }

    /**
     * 次のレベルに必要な経験値を計算する
     * 式: 現在レベル × 現在レベル × 20 + 現在レベル × 30
     */
    public function getRequiredExp(int $currentLevel): int
    {
        return ($currentLevel * $currentLevel * 20) + ($currentLevel * 30);
    }
}
