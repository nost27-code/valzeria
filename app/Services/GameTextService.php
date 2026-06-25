<?php

namespace App\Services;

use App\Models\GameText;
use Illuminate\Support\Facades\Cache;

class GameTextService
{
    private const TTL = 3600;

    public function get(string $key, string $default = ''): string
    {
        return Cache::remember("game_text:{$key}", self::TTL, function () use ($key, $default) {
            return GameText::where('key', $key)->value('value') ?? $default;
        });
    }

    public function set(string $key, string $value, string $description = ''): void
    {
        GameText::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'description' => $description]
        );
        Cache::forget("game_text:{$key}");
    }

    public function forget(string $key): void
    {
        Cache::forget("game_text:{$key}");
    }
}
