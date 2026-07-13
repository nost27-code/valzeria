<?php

namespace App\Console\Commands;

use App\Services\ChampBattleService;
use Illuminate\Console\Command;

class RegenerateChampStats extends Command
{
    protected $signature = 'champ:refresh-stats';
    protected $description = 'Recompute the current champion\'s stored combat stats (champ_states) from live character data.';

    public function handle(ChampBattleService $service): int
    {
        $champ = $service->refreshCurrentChampStats();

        if (! $champ) {
            $this->info('No player-held champion to refresh (NPC-only or no challenger yet).');
            return self::SUCCESS;
        }

        $this->info("Refreshed champ stats for character_id={$champ->character_id}: atk={$champ->atk} def={$champ->def} mag={$champ->mag} spr={$champ->spr} max_hp={$champ->max_hp}");

        return self::SUCCESS;
    }
}
