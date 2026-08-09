<?php

namespace App\Services;

use App\Models\Character;

class JobArtPresetLimitProvider
{
    public function limitFor(Character $character): int
    {
        return max(0, (int) config('battle.job_art_v2.preset_free_limit', 3));
    }
}
