<?php

namespace App\Enums;

enum SixHeroRoomKey: string
{
    case SEAL_MAGIC = 'seal_magic';
    case SEAL_BLADE = 'seal_blade';
    case BURNING_LIFE = 'burning_life';
    case DIVINE_SPEED = 'divine_speed';
    case REVERSE_TIME = 'reverse_time';
    case MIRACLE = 'miracle';

    public function label(): string
    {
        return match ($this) {
            self::SEAL_MAGIC => '封魔の間',
            self::SEAL_BLADE => '封刃の間',
            self::BURNING_LIFE => '灼命の間',
            self::DIVINE_SPEED => '神速の間',
            self::REVERSE_TIME => '逆刻の間',
            self::MIRACLE => '奇跡の間',
        };
    }
}
