<?php

namespace App\Support;

final class ChatDrawerAppearance
{
    public const COLOR_KEYS = [
        'own_bubble_color',
        'other_bubble_color',
        'background_color',
    ];

    private const DEFAULTS = [
        'own_bubble_color' => '#9fe870',
        'other_bubble_color' => '#ffffff',
        'background_color' => '#edf3e8',
        'font_size' => 'normal',
    ];

    private const FONT_SIZES = [
        'small' => ['label' => '小', 'pixels' => 10],
        'normal' => ['label' => '標準', 'pixels' => 11],
        'large' => ['label' => '大', 'pixels' => 13],
        'extra_large' => ['label' => '特大', 'pixels' => 15],
    ];

    private const PRESETS = [
        'standard' => [
            'label' => '標準',
            'description' => 'いつもの明るい配色',
            'colors' => [
                'own_bubble_color' => '#9fe870',
                'other_bubble_color' => '#ffffff',
                'background_color' => '#edf3e8',
            ],
        ],
        'dark' => [
            'label' => 'ダーク',
            'description' => '夜でも眩しさを抑えた配色',
            'colors' => [
                'own_bubble_color' => '#2563eb',
                'other_bubble_color' => '#334155',
                'background_color' => '#0f172a',
            ],
        ],
        'warm' => [
            'label' => '暖色',
            'description' => 'やさしい黄橙系の配色',
            'colors' => [
                'own_bubble_color' => '#f6c453',
                'other_bubble_color' => '#fff7ed',
                'background_color' => '#fef3c7',
            ],
        ],
        'high_contrast' => [
            'label' => '高コントラスト',
            'description' => '黒背景ではっきり見える配色',
            'colors' => [
                'own_bubble_color' => '#ffd400',
                'other_bubble_color' => '#ffffff',
                'background_color' => '#000000',
            ],
        ],
    ];

    public static function defaults(): array
    {
        return self::DEFAULTS;
    }

    public static function normalize(?array $preferences): array
    {
        $preferences ??= [];
        $normalized = self::DEFAULTS;

        foreach (self::COLOR_KEYS as $key) {
            $color = (string) ($preferences[$key] ?? '');
            if (self::isValidColor($color)) {
                $normalized[$key] = strtolower($color);
            }
        }

        $fontSize = (string) ($preferences['font_size'] ?? '');
        if (array_key_exists($fontSize, self::FONT_SIZES)) {
            $normalized['font_size'] = $fontSize;
        }

        return $normalized;
    }

    public static function isValidColor(string $color): bool
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1;
    }

    public static function isValidFontSize(string $fontSize): bool
    {
        return array_key_exists($fontSize, self::FONT_SIZES);
    }

    public static function fontSizeOptions(): array
    {
        return collect(self::FONT_SIZES)
            ->map(fn (array $option, string $key): array => [
                'key' => $key,
                'label' => $option['label'],
                'pixels' => $option['pixels'],
            ])
            ->values()
            ->all();
    }

    public static function presets(): array
    {
        return collect(self::PRESETS)
            ->map(fn (array $preset, string $key): array => [
                'key' => $key,
                'label' => $preset['label'],
                'description' => $preset['description'],
                'colors' => $preset['colors'],
            ])
            ->values()
            ->all();
    }

    public static function presetColors(string $key): ?array
    {
        return self::PRESETS[$key]['colors'] ?? null;
    }

    public static function matchesPreset(array $preferences, string $key): bool
    {
        $colors = self::presetColors($key);
        if ($colors === null) {
            return false;
        }

        $normalized = self::normalize($preferences);

        return collect(self::COLOR_KEYS)
            ->every(fn (string $colorKey): bool => $normalized[$colorKey] === $colors[$colorKey]);
    }

    public static function fontSizePixels(string $fontSize): int
    {
        return self::FONT_SIZES[$fontSize]['pixels'] ?? self::FONT_SIZES[self::DEFAULTS['font_size']]['pixels'];
    }

    public static function contrastTextColor(string $backgroundColor): string
    {
        $color = self::isValidColor($backgroundColor)
            ? $backgroundColor
            : self::DEFAULTS['background_color'];
        $red = hexdec(substr($color, 1, 2));
        $green = hexdec(substr($color, 3, 2));
        $blue = hexdec(substr($color, 5, 2));
        $luminance = (($red * 299) + ($green * 587) + ($blue * 114)) / 1000;

        return $luminance >= 150 ? '#0f172a' : '#ffffff';
    }
}
