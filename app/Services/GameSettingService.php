<?php

namespace App\Services;

use App\Models\GameSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class GameSettingService
{
    public function getFloat(string $key, float $default): float
    {
        return (float) $this->get($key, $default);
    }

    public function getInt(string $key, int $default): int
    {
        return (int) round((float) $this->get($key, $default));
    }

    public function getBool(string $key, bool $default): bool
    {
        $setting = $this->all()[$key] ?? null;
        if (!$setting) {
            return $default;
        }

        $value = is_array($setting) ? ($setting['value'] ?? null) : ($setting->value ?? null);

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function getString(string $key, string $default): string
    {
        $setting = $this->all()[$key] ?? null;
        if (!$setting) {
            return $default;
        }

        $value = is_array($setting) ? ($setting['value'] ?? null) : ($setting->value ?? null);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    public function set(string $key, string $value): void
    {
        GameSetting::where('setting_key', $key)->update(['value' => $value]);
        $this->flush();
    }

    public function flush(): void
    {
        Cache::forget($this->cacheKey());
    }

    public function all(): array
    {
        if (!Schema::hasTable('game_settings')) {
            return [];
        }

        return Cache::remember($this->cacheKey(), now()->addMinutes(5), function () {
            return GameSetting::query()
                ->orderBy('id')
                ->get()
                ->keyBy('setting_key')
                ->map(fn (GameSetting $setting): array => [
                    'value' => $setting->value,
                    'value_type' => $setting->value_type,
                ])
                ->all();
        });
    }

    private function get(string $key, int|float $default): int|float
    {
        $setting = $this->all()[$key] ?? null;
        if (!$setting) {
            return $default;
        }

        $value = is_array($setting) ? ($setting['value'] ?? null) : ($setting->value ?? null);

        return is_numeric($value) ? $value : $default;
    }

    private function cacheKey(): string
    {
        return 'game_settings.all';
    }
}
