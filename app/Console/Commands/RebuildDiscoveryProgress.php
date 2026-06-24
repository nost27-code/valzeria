<?php

namespace App\Console\Commands;

use App\Models\Character;
use App\Services\DiscoveryService;
use Illuminate\Console\Command;

class RebuildDiscoveryProgress extends Command
{
    protected $signature = 'valzeria:rebuild-discovery-progress {--character_id=}';

    protected $description = '既存のエリア解放状況から発見システムの進行状態を再構築します。';

    public function handle(DiscoveryService $discoveryService): int
    {
        $query = Character::query()->orderBy('id');

        if ($this->option('character_id')) {
            $query->where('id', (int) $this->option('character_id'));
        }

        $count = 0;
        $query->chunkById(100, function ($characters) use ($discoveryService, &$count): void {
            foreach ($characters as $character) {
                $result = $discoveryService->rebuildCharacter($character);
                $count++;
                $this->line("character_id={$character->id}: areas={$result['areas']} cities={$result['cities']}");
            }
        });

        $this->info("発見進行の再構築が完了しました。対象キャラクター: {$count}");

        return self::SUCCESS;
    }
}
