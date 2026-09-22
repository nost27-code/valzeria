<?php

namespace Tests\Unit;

use App\Support\ChatDrawerAppearance;
use PHPUnit\Framework\TestCase;

class ChatDrawerAppearanceTest extends TestCase
{
    public function test_it_normalizes_colors_and_font_size(): void
    {
        $preferences = ChatDrawerAppearance::normalize([
            'own_bubble_color' => '#AABBCC',
            'other_bubble_color' => 'red',
            'background_color' => '#101820',
            'font_size' => 'large',
        ]);

        $this->assertSame('#aabbcc', $preferences['own_bubble_color']);
        $this->assertSame('#ffffff', $preferences['other_bubble_color']);
        $this->assertSame('#101820', $preferences['background_color']);
        $this->assertSame('large', $preferences['font_size']);
        $this->assertSame(13, ChatDrawerAppearance::fontSizePixels($preferences['font_size']));
    }

    public function test_it_selects_readable_text_for_light_and_dark_colors(): void
    {
        $this->assertSame('#0f172a', ChatDrawerAppearance::contrastTextColor('#ffffff'));
        $this->assertSame('#ffffff', ChatDrawerAppearance::contrastTextColor('#101820'));
    }

    public function test_it_exposes_accessible_presets_and_detects_color_matches(): void
    {
        $presets = collect(ChatDrawerAppearance::presets())->keyBy('key');

        $this->assertSame(['standard', 'dark', 'warm', 'high_contrast'], $presets->keys()->all());
        $this->assertTrue(ChatDrawerAppearance::matchesPreset(ChatDrawerAppearance::defaults(), 'standard'));
        $this->assertTrue(ChatDrawerAppearance::matchesPreset([
            ...ChatDrawerAppearance::defaults(),
            ...ChatDrawerAppearance::presetColors('dark'),
            'font_size' => 'extra_large',
        ], 'dark'));
        $this->assertFalse(ChatDrawerAppearance::matchesPreset(ChatDrawerAppearance::defaults(), 'unknown'));
        $this->assertNull(ChatDrawerAppearance::presetColors('unknown'));
    }
}
