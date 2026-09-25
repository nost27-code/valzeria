<?php

namespace App\Support;

final class NationRaidUiCatalog
{
    private const CHAMPION_CROWN_PATH = '/images/raid/raid_champion_crown.webp';

    private const MAX_ACTION_EMBLEM_PATH = '/images/raid/raid_max_action_emblem.webp';

    public static function championCrownUrl(): string
    {
        return self::versionedAsset(self::CHAMPION_CROWN_PATH);
    }

    public static function maxActionEmblemUrl(): string
    {
        return self::versionedAsset(self::MAX_ACTION_EMBLEM_PATH);
    }

    private static function versionedAsset(string $path): string
    {
        $absolutePath = public_path(ltrim($path, '/'));
        $version = is_file($absolutePath) ? (string) filemtime($absolutePath) : '1';

        return asset($path).'?v='.$version;
    }
}
