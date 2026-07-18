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
        $this->forgetRelatedPrefixCache($key);
    }

    public function forget(string $key): void
    {
        Cache::forget("game_text:{$key}");
        $this->forgetRelatedPrefixCache($key);
    }

    /**
     * Bulk-load all game_text values whose key starts with $prefix.
     * Returns an associative array of key => value.
     */
    public function getAllForPrefix(string $prefix): array
    {
        return Cache::remember($this->prefixCacheKey($prefix), self::TTL, fn (): array => GameText::query()
            ->where('key', 'like', $prefix . '%')
            ->pluck('value', 'key')
            ->all());
    }

    private function forgetRelatedPrefixCache(string $key): void
    {
        if (preg_match('/^(fac\.[^.]+\.)/', $key, $matches) === 1) {
            Cache::forget($this->prefixCacheKey($matches[1]));
        }
    }

    private function prefixCacheKey(string $prefix): string
    {
        return 'game_text_prefix:' . sha1($prefix);
    }
}
