<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Character;
use App\Services\CharacterStatusService;

class ScaleCharacterStats extends Command
{
    protected $signature = 'characters:scale-stats {multiplier=1.1 : 倍率（例: 1.1）}';
    protected $description = '既存キャラクターの基礎ステータスを指定倍率で一括スケールする';

    public function handle(): int
    {
        $multiplier = (float) $this->argument('multiplier');

        if ($multiplier <= 0) {
            $this->error('倍率は0より大きい値を指定してください。');
            return 1;
        }

        $characters = Character::all();
        $count = $characters->count();

        if ($count === 0) {
            $this->info('対象キャラクターが存在しません。');
            return 0;
        }

        $this->info("対象: {$count} キャラクター、倍率: {$multiplier}");

        if (!$this->confirm('続行しますか？')) {
            $this->info('キャンセルしました。');
            return 0;
        }

        $statusService = app(CharacterStatusService::class);
        $bar = $this->output->createProgressBar($count);
        $bar->start();

        foreach ($characters as $character) {
            $character->hp_base     = (int) round($character->hp_base     * $multiplier);
            $character->mp_base     = (int) round($character->mp_base     * $multiplier);
            $character->attack_base = (int) round($character->attack_base * $multiplier);
            $character->defense_base = (int) round($character->defense_base * $multiplier);
            $character->speed_base  = (int) round($character->speed_base  * $multiplier);
            $character->magic_base  = (int) round($character->magic_base  * $multiplier);
            $character->spirit_base = (int) round($character->spirit_base * $multiplier);
            $character->luck_base   = (int) round($character->luck_base   * $multiplier);

            // current_hp/mp を新しい最大値に合わせて回復
            $finalStats = $statusService->getFinalStats($character);
            $character->current_hp = $finalStats['max_hp'] ?? $character->hp_base;
            $character->current_mp = $finalStats['max_mp'] ?? $character->mp_base;

            $character->save();
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("完了: {$count} キャラクターのステータスを {$multiplier} 倍に更新しました。");

        return 0;
    }
}
