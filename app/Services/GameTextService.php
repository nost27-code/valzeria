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

    /**
     * Bulk-load all game_text values whose key starts with $prefix.
     * Returns an associative array of key => value.
     */
    public function getAllForPrefix(string $prefix): array
    {
        $rows = GameText::where('key', 'like', $prefix . '%')->get(['key', 'value']);
        $result = [];
        foreach ($rows as $row) {
            $result[$row->key] = $row->value;
            Cache::put("game_text:{$row->key}", $row->value, self::TTL);
        }
        return $result;
    }
}
