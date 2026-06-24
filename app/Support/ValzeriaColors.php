<?php

namespace App\Support;

final class ValzeriaColors
{
    public const BLUE = '#1e40af';
    public const BLUE_DARK = '#1e3a8a';
    public const BLUE_DEEP = '#0a1628';
    public const GOLD = '#d4af37';
    public const GOLD_DARK = '#b8860b';
    public const GOLD_SOFT = '#fef3c7';

    public static function palette(): array
    {
        return [
            'blue' => self::BLUE,
            'blue_dark' => self::BLUE_DARK,
            'blue_deep' => self::BLUE_DEEP,
            'gold' => self::GOLD,
            'gold_dark' => self::GOLD_DARK,
            'gold_soft' => self::GOLD_SOFT,
        ];
    }
}
