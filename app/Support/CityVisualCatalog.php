<?php

namespace App\Support;

class CityVisualCatalog
{
    private const ICONS = [
        1 => 'symbol/01.royal-capital-arclea.webp',
        2 => 'symbol/02.port-town-marines.webp',
        3 => 'symbol/03.spirit-city-elphia.webp',
        4 => 'symbol/04.steel-city-granberg.webp',
        5 => 'symbol/05.snow-city-frostria.webp',
        6 => 'symbol/06.sand-city-sandra.png.webp',
        7 => 'symbol/07.arcane-city-luminous.webp',
        8 => 'symbol/08.demon-realm-city-necrom.webp',
        9 => 'symbol/09.sky-city-celestia.webp',
        10 => 'symbol/10.demon-king-castle-valzeria.webp',
        101 => 'symbol/11.ferdia_ruvan_symbol_icon_white.webp',
        102 => 'symbol/12.ferdia_granford_symbol_icon_white.webp',
        103 => 'symbol/13.ferdia_arven_symbol_icon_white.webp',
    ];

    private const BACKGROUND_DIRECTORY = 'cities';

    private const IMAGE_NUMBERS = [
        101 => 11,
        102 => 12,
        103 => 13,
    ];

    public static function icon(?int $cityId): string
    {
        return self::ICONS[$cityId] ?? 'emblem.webp';
    }

    public static function background(?int $cityId): ?string
    {
        $fileName = self::backgroundFileName($cityId);
        if ($fileName === null) {
            return null;
        }

        $path = self::BACKGROUND_DIRECTORY . '/' . $fileName;

        return file_exists(public_path('images/' . $path)) ? $path : null;
    }

    public static function cardBackground(?int $cityId): ?string
    {
        $fileName = self::cardBackgroundFileName($cityId);
        if ($fileName === null) {
            return null;
        }

        $path = self::BACKGROUND_DIRECTORY . '/' . $fileName;

        return file_exists(public_path('images/' . $path)) ? $path : null;
    }

    public static function backgroundDirectory(): string
    {
        return 'public/images/' . self::BACKGROUND_DIRECTORY;
    }

    public static function backgroundFileName(?int $cityId): ?string
    {
        $imageNumber = self::imageNumber($cityId);
        if ($imageNumber === null) {
            return null;
        }

        return sprintf('city%02d_side.webp', $imageNumber);
    }

    public static function cardBackgroundFileName(?int $cityId): ?string
    {
        $imageNumber = self::imageNumber($cityId);
        if ($imageNumber === null) {
            return null;
        }

        return sprintf('city%02d.webp', $imageNumber);
    }

    private static function imageNumber(?int $cityId): ?int
    {
        if ($cityId === null || $cityId < 1) {
            return null;
        }

        return self::IMAGE_NUMBERS[$cityId] ?? ($cityId <= 99 ? $cityId : null);
    }
}
