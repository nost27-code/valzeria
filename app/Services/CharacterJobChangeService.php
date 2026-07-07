<?php

namespace App\Services;

use App\Models\Character;
use App\Models\JobClass;
use App\Models\JobChangeLog;
use App\Support\JobRankCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CharacterJobChangeService
{
    protected PublicLogService $publicLogService;
    protected array $lastUnequipMessages = [];
    protected string $lastFailureMessage = '';

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

        if ((int) ($character->bonus_points ?? 0) > 0) {
            return [
                'success' => false,
                'message' => '未使用BPをすべて能力へ割り振ってから転職してください。'
            ];
        }

        $jobService = app(JobService::class);

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

        if (! $jobService->isReleasedForJobChange($targetJob)) {
            return [
                'success' => false,
                'message' => 'この職業には現在転職できません。'
            ];
        }

        if (! $jobService->meetsJobRequirements($character, $targetJob)) {
            return [
                'success' => false,
                'message' => 'この職業の転職条件を満たしていません。'
            ];
        }

        return [
            'success' => true,
            'message' => ''
        ];
    }

    /**
     * 引き継ぎ後の基礎ステータスを計算する
     * 
     * 転職先ランクに応じて、現在の基礎ステータスを圧縮して引き継ぐ。
     */
    public function calculateInheritedStats(Character $character, ?JobClass $targetJob = null): array
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

        $inheritanceRate = JobRankCatalog::inheritanceRate($targetJob?->rank);

        $afterHp = floor($character->hp_base * $inheritanceRate);
        $afterMp = floor($character->mp_base * $inheritanceRate);
        $afterStr = floor($character->attack_base * $inheritanceRate);
        $afterDef = floor($character->defense_base * $inheritanceRate);
        $afterAgi = floor($character->speed_base * $inheritanceRate);
        $afterMag = floor($character->magic_base * $inheritanceRate);
        $afterSpr = floor($character->spirit_base * $inheritanceRate);
        $afterLuk = floor($character->luck_base * $inheritanceRate);

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
        $calculated = $this->calculateInheritedStats($character, $targetJob);

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
        $this->lastFailureMessage = '';
        $canChange = $this->canChangeJob($character, $targetJob);
        if (!$canChange['success']) {
            $this->lastFailureMessage = (string) ($canChange['message'] ?? '転職条件を満たしていません。');
            return false;
        }

        try {
            DB::transaction(function () use ($character, $targetJob) {
                $calculated = $this->calculateInheritedStats($character, $targetJob);
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
                $character->spirit_fraction = 0.0;
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
            $this->lastFailureMessage = '転職処理に失敗しました。条件を満たしているか確認してください。';
            return false;
        }
    }

    public function getLastUnequipMessages(): array
    {
        return $this->lastUnequipMessages;
    }

    public function getLastFailureMessage(): string
    {
        return $this->lastFailureMessage;
    }
}
