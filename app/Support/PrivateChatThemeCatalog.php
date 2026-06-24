<?php

namespace App\Support;

class PrivateChatThemeCatalog
{
    public const DEFAULT_KEY = 'valzeria_blue';

    public static function default(): array
    {
        return self::theme(self::DEFAULT_KEY);
    }

    public static function theme(?string $key): array
    {
        return self::themes()[$key ?: self::DEFAULT_KEY] ?? self::themes()[self::DEFAULT_KEY];
    }

    public static function themes(): array
    {
        return [
            self::DEFAULT_KEY => [
                'key' => self::DEFAULT_KEY,
                'name' => 'ヴァルゼリア・ブルー',
                'short_name' => 'ブルー',
                'panel_bg' => '#ffffff',
                'panel_border' => '#fbbf24',
                'header_bg' => '#fffbeb',
                'header_border' => '#fde68a',
                'thread_bg' => '#eef5fb',
                'own_bubble_bg' => ValzeriaColors::BLUE,
                'own_bubble_text' => '#ffffff',
                'partner_bubble_bg' => '#ffffff',
                'partner_bubble_text' => '#1e293b',
                'input_bg' => '#ffffff',
                'input_border' => '#e2e8f0',
                'accent' => ValzeriaColors::GOLD,
            ],
            'valzeria_gold' => [
                'key' => 'valzeria_gold',
                'name' => 'ヴァルゼリア・ゴールド',
                'short_name' => 'ゴールド',
                'panel_bg' => '#ffffff',
                'panel_border' => ValzeriaColors::GOLD,
                'header_bg' => '#fff8e1',
                'header_border' => '#f8e6a0',
                'thread_bg' => '#fffaf0',
                'own_bubble_bg' => ValzeriaColors::GOLD_DARK,
                'own_bubble_text' => '#ffffff',
                'partner_bubble_bg' => '#ffffff',
                'partner_bubble_text' => '#1e293b',
                'input_bg' => '#ffffff',
                'input_border' => '#f1d989',
                'accent' => ValzeriaColors::BLUE,
            ],
        ];
    }
}
