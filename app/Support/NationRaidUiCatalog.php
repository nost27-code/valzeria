<?php

namespace App\Support;

final class NationRaidUiCatalog
{
    private const CHAMPION_CROWN_PATH = '/images/raid/raid_champion_crown.webp';

    public static function championCrownUrl(): string
    {
        $absolutePath = public_path(ltrim(self::CHAMPION_CROWN_PATH, '/'));
        $version = is_file($absolutePath) ? (string) filemtime($absolutePath) : '1';

        return asset(self::CHAMPION_CROWN_PATH).'?v='.$version;
    }
}
