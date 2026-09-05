<?php

namespace App\Services\Nation\Raid;

interface NationRaidRandomSource
{
    public function nextInt(int $minimum, int $maximum): int;
}
