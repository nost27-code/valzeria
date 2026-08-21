<?php

namespace App\Enums;

enum SixHeroBattleMode: string
{
    case OFFICIAL = 'official';
    case PRACTICE = 'practice';

    public function label(): string
    {
        return match ($this) {
            self::OFFICIAL => '公式戦',
            self::PRACTICE => '相性確認',
        };
    }
}
