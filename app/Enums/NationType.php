<?php

namespace App\Enums;

enum NationType: string
{
    case KINGDOM = 'kingdom';
    case EMPIRE = 'empire';
    case DUCHY = 'duchy';
    case REPUBLIC = 'republic';
    case KNIGHT_STATE = 'knight_state';

    public function label(): string
    {
        return match ($this) {
            self::KINGDOM => '王国',
            self::EMPIRE => '帝国',
            self::DUCHY => '公国',
            self::REPUBLIC => '共和国',
            self::KNIGHT_STATE => '騎士国',
        };
    }

    public function rulerTitle(): string
    {
        return match ($this) {
            self::KINGDOM => '国王',
            self::EMPIRE => '皇帝',
            self::DUCHY => '大公',
            self::REPUBLIC => '執政官',
            self::KNIGHT_STATE => '騎士団長',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(
            static fn (self $type): string => $type->value,
            self::cases(),
        );
    }
}
