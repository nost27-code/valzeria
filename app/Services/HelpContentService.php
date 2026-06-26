<?php

namespace App\Services;

use App\Models\GameText;
use Illuminate\Support\Facades\Schema;

class HelpContentService
{
    public const PREFIX = 'help';

    public function content(bool $editable = false): array
    {
        $defaults = $this->defaults();
        $keys = $this->textKeys($defaults);
        $overrides = Schema::hasTable('game_texts')
            ? GameText::whereIn('key', $keys)->pluck('value', 'key')
            : collect();

        $instruction = $overrides->get(self::PREFIX . '.instruction') ?? $defaults['instruction'];
        $footer = $overrides->get(self::PREFIX . '.footer') ?? $defaults['footer'];

        $sections = [];
        foreach ($defaults['sections'] as $section) {
            $slug = $section['slug'];
            $prefix = self::PREFIX . ".sections.{$slug}";

            $sections[] = [
                'slug' => $slug,
                'icon_image' => $overrides->get("{$prefix}.icon_image") ?? $section['icon_image'],
                'title' => $overrides->get("{$prefix}.title") ?? $section['title'],
                'body' => $editable
                    ? ($overrides->get("{$prefix}.body") ?? $section['body'])
                    : $this->replacePlaceholders($overrides->get("{$prefix}.body") ?? $section['body']),
            ];
        }

        return [
            'instruction' => $editable ? $instruction : $this->replacePlaceholders($instruction),
            'footer' => $editable ? $footer : $this->replacePlaceholders($footer),
            'sections' => $sections,
        ];
    }

    public function defaults(): array
    {
        return config('help_content');
    }

    public function textKeys(?array $defaults = null): array
    {
        $defaults ??= $this->defaults();
        $keys = [
            self::PREFIX . '.instruction',
            self::PREFIX . '.footer',
        ];

        foreach ($defaults['sections'] as $section) {
            $prefix = self::PREFIX . ".sections.{$section['slug']}";
            $keys[] = "{$prefix}.icon_image";
            $keys[] = "{$prefix}.title";
            $keys[] = "{$prefix}.body";
        }

        return $keys;
    }

    private function replacePlaceholders(string $text): string
    {
        $cooldowns = app(CooldownSettingService::class);

        return strtr($text, [
            '{{battle_cooldown_seconds}}' => (string) $cooldowns->explorationBattleSeconds(),
            '{battle_cooldown_seconds}' => (string) $cooldowns->explorationBattleSeconds(),
            '{{inn_cooldown_seconds}}' => (string) $cooldowns->innSeconds(),
            '{inn_cooldown_seconds}' => (string) $cooldowns->innSeconds(),
        ]);
    }
}
