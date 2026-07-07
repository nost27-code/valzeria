<?php

namespace App\Services;

use App\Models\GameSetting;
use Carbon\CarbonImmutable;

class ExtraContentControlService
{
    public const ENABLED_KEY_PREFIX = 'extra_content.enabled.';
    public const STARTS_AT_KEY_PREFIX = 'extra_content.starts_at.';
    public const ENDS_AT_KEY_PREFIX = 'extra_content.ends_at.';

    public function allStatuses(): array
    {
        return collect(config('extra_content.contents', []))
            ->map(fn (array $content, string $key): array => $this->statusFor($key, $content))
            ->all();
    }

    public function isEnabled(string $contentKey, ?array $content = null): bool
    {
        $content ??= config("extra_content.contents.{$contentKey}");
        if (!is_array($content)) {
            return false;
        }

        return app(GameSettingService::class)->getBool(
            $this->enabledSettingKey($contentKey),
            $this->defaultEnabled($content)
        );
    }

    public function isActive(string $contentKey, ?array $content = null): bool
    {
        $content ??= config("extra_content.contents.{$contentKey}");
        if (!is_array($content) || !$this->isEnabled($contentKey, $content)) {
            return false;
        }

        $period = $this->periodFor($contentKey);

        return (bool) ($period['active'] ?? false);
    }

    public function setEnabled(string $contentKey, bool $enabled): void
    {
        $content = config("extra_content.contents.{$contentKey}");
        if (!is_array($content)) {
            throw new \InvalidArgumentException("Unknown extra content: {$contentKey}");
        }

        GameSetting::updateOrCreate(
            ['setting_key' => $this->enabledSettingKey($contentKey)],
            [
                'label' => (string) ($content['setting_label'] ?? "{$content['name']} 公開状態"),
                'value' => $enabled ? '1' : '0',
                'value_type' => 'boolean',
                'description' => '期間公開コンテンツのON/OFF。OFFにすると街・探索一覧から非表示になり、直URLからも入れません。',
            ]
        );

        app(GameSettingService::class)->flush();
    }

    public function setPeriod(string $contentKey, ?string $startsAt, ?string $endsAt): void
    {
        $content = config("extra_content.contents.{$contentKey}");
        if (!is_array($content)) {
            throw new \InvalidArgumentException("Unknown extra content: {$contentKey}");
        }

        $normalizedStartsAt = $this->normalizeDateTime($startsAt);
        $normalizedEndsAt = $this->normalizeDateTime($endsAt);
        if ($normalizedStartsAt && $normalizedEndsAt && $normalizedStartsAt->gt($normalizedEndsAt)) {
            throw new \InvalidArgumentException('開催終了日時は開始日時より後にしてください。');
        }

        $settings = [
            $this->startsAtSettingKey($contentKey) => [
                'label' => "{$content['name']} 開催開始",
                'value' => $normalizedStartsAt?->format('Y-m-d H:i') ?? '',
                'value_type' => 'string',
                'description' => '期間公開コンテンツの開催開始日時（日本時間）。空欄なら即時開始扱い。',
            ],
            $this->endsAtSettingKey($contentKey) => [
                'label' => "{$content['name']} 開催終了",
                'value' => $normalizedEndsAt?->format('Y-m-d H:i') ?? '',
                'value_type' => 'string',
                'description' => '期間公開コンテンツの開催終了日時（日本時間）。空欄なら終了なし。',
            ],
        ];

        foreach ($settings as $key => $setting) {
            GameSetting::updateOrCreate(['setting_key' => $key], $setting);
        }

        app(GameSettingService::class)->flush();
    }

    public function clearPeriod(string $contentKey): void
    {
        $this->setPeriod($contentKey, null, null);
    }

    public function enabledSettingKey(string $contentKey): string
    {
        return self::ENABLED_KEY_PREFIX . $contentKey;
    }

    public function startsAtSettingKey(string $contentKey): string
    {
        return self::STARTS_AT_KEY_PREFIX . $contentKey;
    }

    public function endsAtSettingKey(string $contentKey): string
    {
        return self::ENDS_AT_KEY_PREFIX . $contentKey;
    }

    public function periodFor(string $contentKey): array
    {
        $settings = app(GameSettingService::class);
        $startsAt = $this->normalizeDateTime($settings->getString($this->startsAtSettingKey($contentKey), ''));
        $endsAt = $this->normalizeDateTime($settings->getString($this->endsAtSettingKey($contentKey), ''));
        $now = CarbonImmutable::now('Asia/Tokyo');
        $active = (!$startsAt || $now->gte($startsAt))
            && (!$endsAt || $now->lte($endsAt));

        return [
            'starts_at' => $startsAt?->format('Y-m-d H:i'),
            'ends_at' => $endsAt?->format('Y-m-d H:i'),
            'starts_at_input' => $startsAt?->format('Y-m-d\TH:i') ?? '',
            'ends_at_input' => $endsAt?->format('Y-m-d\TH:i') ?? '',
            'scheduled' => $startsAt !== null || $endsAt !== null,
            'active' => $active,
            'status_label' => $this->periodStatusLabel($active, $startsAt, $endsAt, $now),
        ];
    }

    private function statusFor(string $contentKey, array $content): array
    {
        $enabled = $this->isEnabled($contentKey, $content);
        $period = $this->periodFor($contentKey);

        return [
            'key' => $contentKey,
            'name' => (string) ($content['name'] ?? $contentKey),
            'category' => (string) ($content['category'] ?? '追加コンテンツ'),
            'description' => (string) ($content['description'] ?? ''),
            'route' => (string) ($content['route'] ?? ''),
            'enabled' => $enabled,
            'default_enabled' => $this->defaultEnabled($content),
            'active' => $enabled && (bool) ($period['active'] ?? false),
            'period' => $period,
            'enabled_setting_key' => $this->enabledSettingKey($contentKey),
            'starts_at_setting_key' => $this->startsAtSettingKey($contentKey),
            'ends_at_setting_key' => $this->endsAtSettingKey($contentKey),
        ];
    }

    private function defaultEnabled(array $content): bool
    {
        return (bool) ($content['default_enabled'] ?? false);
    }

    private function normalizeDateTime(?string $value): ?CarbonImmutable
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return CarbonImmutable::parse($value, 'Asia/Tokyo');
    }

    private function periodStatusLabel(bool $active, ?CarbonImmutable $startsAt, ?CarbonImmutable $endsAt, CarbonImmutable $now): string
    {
        if ($active) {
            return '開催中';
        }

        if ($startsAt && $now->lt($startsAt)) {
            return '開始前';
        }

        if ($endsAt && $now->gt($endsAt)) {
            return '終了済み';
        }

        return '期間外';
    }
}
