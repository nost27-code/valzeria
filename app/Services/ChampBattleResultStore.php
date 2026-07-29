<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

class ChampBattleResultStore
{
    private const CACHE_KEY_PREFIX = 'champ_battle_result';

    public function store(int $characterId, array $result): string
    {
        $token = (string) Str::uuid();
        $payload = json_encode(
            $result,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        if (! Cache::put($this->cacheKey($characterId, $token), $payload, $this->expiresAt($result))) {
            throw new RuntimeException('チャンプ戦結果を一時保存できませんでした。');
        }

        return $token;
    }

    public function retrieve(int $characterId, ?string $token): ?array
    {
        if (! is_string($token) || ! Str::isUuid($token)) {
            return null;
        }

        $key = $this->cacheKey($characterId, $token);
        $payload = Cache::get($key);
        if (! is_string($payload) || $payload === '') {
            return null;
        }

        try {
            $result = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            Cache::forget($key);

            return null;
        }

        return is_array($result) ? $result : null;
    }

    private function cacheKey(int $characterId, string $token): string
    {
        return self::CACHE_KEY_PREFIX.':'.$characterId.':'.$token;
    }

    private function expiresAt(array $result): Carbon
    {
        $minimumExpiry = now()->addMinute();
        $nextAvailableAt = $result['next_available_at'] ?? null;
        if (! $nextAvailableAt) {
            return $minimumExpiry;
        }

        try {
            $cooldownExpiry = Carbon::parse($nextAvailableAt);
        } catch (\Throwable) {
            return $minimumExpiry;
        }

        return $cooldownExpiry->gt($minimumExpiry) ? $cooldownExpiry : $minimumExpiry;
    }
}
