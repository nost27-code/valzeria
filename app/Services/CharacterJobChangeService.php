<?php

namespace App\Services;

use App\Models\Character;
use App\Models\JobClass;
use App\Models\JobChangeLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CharacterJobChangeService
{
    protected PublicLogService $publicLogService;
    protected array $lastUnequipMessages = [];

    public function __construct(PublicLogService $publicLogService)
    {
        $this->publicLogService = $publicLogService;
    }

    /**
     * 転職可能かどうかの判定
     */
    public function canChangeJob(Character $character, JobClass $targetJob): array
    {
        if ($character->level < 30) {
            return [
                'success' => false,
                'message' => 'Lv30に到達しないと転職できません。'
            ];
        }

        if ($character->level < 100) {
            // 現在の職業がマスター済みかチェック
            $currentJobId = $character->current_job_id;
            $isMastered = false;
            if ($currentJobId) {
                $isMastered = $character->jobHistories()
                    ->where('job_class_id', $currentJobId)
                    ->where('is_mastered', true)
                    ->exists();
            }

            if (!$isMastered) {
                return [
                    'success' => false,
                    'message' => 'Lv100に到達するか、現在の職業をマスターしないと転職できません。'
                ];
            }
        }

        if ($character->current_job_id === $targetJob->id) {
            return [
                'success' => false,
                'message' => '現在の職業と同じ職業には転職できません。'
            ];
        }

        if (!$targetJob->is_active) {
            return [
                'success' => false,
                'message' => 'この職業には現在転職できません。'
            ];
        }

        // TODO: 上位職の解放条件チェックが必要になればここに追加

        return [
            'success' => true,
            'message' => ''
        ];
    }

    /**
     * 引き継ぎ後の基礎ステータスを計算する
     * 
     * 現在の基礎ステータスの50%を引き継ぐ
     */
    public function calculateInheritedStats(Character $character): array
    {
        // 最低保証値
        $minStats = [
            'hp' => 100,
            'mp' => 30,
            'str' => 10,
            'def' => 10,
            'agi' => 10,
            'mag' => 10,
            'spr' => 10,
            'luk' => 10,
        ];

        // 50%引き継ぎ計算
        $afterHp = floor($character->hp_base * 0.5);
        $afterMp = floor($character->mp_base * 0.5);
        $afterStr = floor($character->attack_base * 0.5);
        $afterDef = floor($character->defense_base * 0.5);
        $afterAgi = floor($character->speed_base * 0.5);
        $afterMag = floor($character->magic_base * 0.5);
        $afterSpr = floor($character->spirit_base * 0.5);
        $afterLuk = floor($character->luck_base * 0.5);

        // 最低保証を下回らないようにする
        return [
            'hp_base' => max($minStats['hp'], (int)$afterHp),
            'mp_base' => max($minStats['mp'], (int)$afterMp),
            'attack_base' => max($minStats['str'], (int)$afterStr),
            'defense_base' => max($minStats['def'], (int)$afterDef),
            'speed_base' => max($minStats['agi'], (int)$afterAgi),
            'magic_base' => max($minStats['mag'], (int)$afterMag),
            'spirit_base' => max($minStats['spr'], (int)$afterSpr),
            'luck_base' => max($minStats['luk'], (int)$afterLuk),
        ];
    }

    /**
     * 転職確認画面用の予測値を返す
     */
    public function previewJobChange(Character $character, JobClass $targetJob): array
    {
        $calculated = $this->calculateInheritedStats($character);

        return [
            'before' => [
                'level' => $character->level,
                'reincarnation_count' => $character->reincarnation_count,
                'hp' => $character->hp_base,
                'mp' => $character->mp_base,
                'str' => $character->attack_base,
                'def' => $character->defense_base,
                'agi' => $character->speed_base,
                'mag' => $character->magic_base,
                'spr' => $character->spirit_base,
                'luk' => $character->luck_base,
            ],
            'after' => [
                'level' => 1,
                'reincarnation_count' => $character->reincarnation_count + 1,
                'hp' => $calculated['hp_base'],
                'mp' => $calculated['mp_base'],
                'str' => $calculated['attack_base'],
                'def' => $calculated['defense_base'],
                'agi' => $calculated['speed_base'],
                'mag' => $calculated['magic_base'],
                'spr' => $calculated['spirit_base'],
                'luk' => $calculated['luck_base'],
            ]
        ];
    }

    /**
     * 転職処理の実行
     */
    public function changeJob(Character $character, JobClass $targetJob): bool
    {
        $this->lastUnequipMessages = [];
        $canChange = $this->canChangeJob($character, $targetJob);
        if (!$canChange['success']) {
            return false;
        }

        try {
            DB::transaction(function () use ($character, $targetJob) {
                $calculated = $this->calculateInheritedStats($character);
                $fromJobId = $character->current_job_id;
                
                // 転職履歴の保存
                JobChangeLog::create([
                    'character_id' => $character->id,
                    'from_job_id' => $fromJobId,
                    'to_job_id' => $targetJob->id,
                    'before_level' => $character->level,
                    'reincarnation_count_before' => $character->reincarnation_count,
                    'reincarnation_count_after' => $character->reincarnation_count + 1,
                    'before_max_hp' => $character->hp_base,
                    'before_str' => $character->attack_base,
                    'before_def' => $character->defense_base,
                    'before_agi' => $character->speed_base,
                    'before_mag' => $character->magic_base,
                    'before_luk' => $character->luck_base,
                    'after_max_hp' => $calculated['hp_base'],
                    'after_str' => $calculated['attack_base'],
                    'after_def' => $calculated['defense_base'],
                    'after_agi' => $calculated['speed_base'],
                    'after_mag' => $calculated['magic_base'],
                    'after_luk' => $calculated['luck_base'],
                    // Note: job_change_logs に spr カラムが無い場合はマイグレーションが必要だが
                    // 今回は既存テーブルを優先してそのまま
                ]);

                // キャラクターの更新
                $character->current_job_id = $targetJob->id;
                $character->level = 1;
                $character->exp = 0;
                $character->reincarnation_count += 1;
                
                $character->hp_base = $calculated['hp_base'];
                $character->mp_base = $calculated['mp_base'];
                $character->attack_base = $calculated['attack_base'];
                $character->defense_base = $calculated['defense_base'];
                $character->speed_base = $calculated['speed_base'];
                $character->magic_base = $calculated['magic_base'];
                $character->spirit_base = $calculated['spirit_base'];
                $character->luck_base = $calculated['luck_base'];
                
                // 転職時に端数（小数点以下の成長ストック）をリセット
                $character->hp_fraction = 0.0;
                $character->mp_fraction = 0.0;
                $character->attack_fraction = 0.0;
                $character->defense_fraction = 0.0;
                $character->speed_fraction = 0.0;
                $character->magic_fraction = 0.0;
                $character->luck_fraction = 0.0;
                
                // HP, SP全回復
                $character->current_hp = $calculated['hp_base'];
                $character->current_mp = $calculated['mp_base'];
                
                $character->save();
                $character->refresh();

                $this->lastUnequipMessages = app(EquipmentAutoUnequipService::class)->unequipInvalidItems($character);

                // 公開ログの作成
                $fromJobName = $fromJobId ? JobClass::find($fromJobId)->name : '無職';
                $message = "【転職】{$character->name}さんが{$fromJobName}から{$targetJob->name}へ転職しました！（転職{$character->reincarnation_count}回目）";
                $this->publicLogService->addLog('job_change', $message, $character, 2);
            });
            
            return true;
        } catch (\Exception $e) {
            Log::error('転職処理に失敗しました: ' . $e->getMessage());
            return false;
        }
    }

    public function getLastUnequipMessages(): array
    {
        return $this->lastUnequipMessages;
    }
}
