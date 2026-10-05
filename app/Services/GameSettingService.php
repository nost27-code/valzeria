<?php

namespace App\Services;

use App\Models\GameSetting;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class GameSettingService
{
    private bool $snapshotActive = false;

    private ?array $snapshot = null;

    /** Reuse fresh DB settings for one operation, without touching shared cache. */
    public function withFreshSnapshot(callable $operation): mixed
    {
        if ($this->snapshotActive) {
            return $operation();
        }

        $this->snapshotActive = true;
        try {
            return $operation();
        } finally {
            $this->snapshot = null;
            $this->snapshotActive = false;
        }
    }

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
        $this->snapshot = null;
        Cache::forget($this->cacheKey());
    }

    public function all(): array
    {
        if ($this->snapshotActive) {
            return $this->snapshot ??= app(SchemaStateService::class)->hasTable('game_settings')
                ? $this->loadSettings()
                : [];
        }

        if (! app(SchemaStateService::class)->hasTable('game_settings')) {
            return [];
        }

        // DBキャッシュは期限切れの読み取りでもDELETE/INSERTが発生する。
        // 探索等でプレイヤー行をロックしている間は共有cache行をロックしない。
        if (DB::transactionLevel() > 0 && Cache::getStore() instanceof DatabaseStore) {
            return $this->loadSettings();
        }

        return Cache::remember($this->cacheKey(), now()->addMinutes(5), fn () => $this->loadSettings());
    }

    private function loadSettings(): array
    {
        return GameSetting::query()
            ->orderBy('id')
            ->get()
            ->keyBy('setting_key')
            ->map(fn (GameSetting $setting): array => [
                'value' => $setting->value,
                'value_type' => $setting->value_type,
            ])
            ->all();
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
