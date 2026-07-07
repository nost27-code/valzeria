<?php

namespace App\Services;

use App\Models\GameSetting;
use Carbon\CarbonImmutable;

class AdventureSupportItemControlService
{
    public const KEY_PREFIX = 'adventure_support.item_enabled.';
    public const VISIBILITY_KEY_PREFIX = 'adventure_support.item_visible.';
    public const CAMPAIGN_KEY_PREFIX = 'adventure_support.item_campaign.';

    public function allStatuses(): array
    {
        return collect(config('adventure_support.items', []))
            ->map(fn (array $item, string $key): array => $this->statusFor($key, $item))
            ->all();
    }

    public function effectiveItem(string $itemKey, array $item): array
    {
        $campaign = $this->campaignFor($itemKey, $item);
        if (!($campaign['active'] ?? false)) {
            return $item;
        }

        $normalPrice = (int) ($item['price'] ?? 0);
        $campaignPrice = (int) ($campaign['price'] ?? 0);
        if ($normalPrice <= 0 || $campaignPrice <= 0) {
            return $item;
        }

        $item['price'] = $campaignPrice;
        $item['campaign'] = $campaign;

        if ($campaignPrice < $normalPrice) {
            $item['original_price'] = $normalPrice;
            $item['sale_ends_at'] = $campaign['ends_at'] ?? null;
            $item['sale_starts_at'] = $campaign['starts_at'] ?? null;
        }

        return $item;
    }

    public function isEnabled(string $itemKey, ?array $item = null): bool
    {
        $item ??= config("adventure_support.items.{$itemKey}");
        if (!is_array($item)) {
            return false;
        }

        return app(GameSettingService::class)->getBool(
            $this->settingKey($itemKey),
            $this->defaultEnabled($item)
        );
    }

    public function isVisible(string $itemKey, ?array $item = null): bool
    {
        $item ??= config("adventure_support.items.{$itemKey}");
        if (!is_array($item)) {
            return false;
        }

        return app(GameSettingService::class)->getBool(
            $this->visibilitySettingKey($itemKey),
            $this->defaultVisible($item)
        );
    }

    public function setEnabled(string $itemKey, bool $enabled): void
    {
        $item = config("adventure_support.items.{$itemKey}");
        if (!is_array($item)) {
            throw new \InvalidArgumentException("Unknown adventure support item: {$itemKey}");
        }

        GameSetting::updateOrCreate(
            ['setting_key' => $this->settingKey($itemKey)],
            [
                'label' => "{$item['name']} 販売状態",
                'value' => $enabled ? '1' : '0',
                'value_type' => 'boolean',
                'description' => '補給商会の商品販売ON/OFF。OFFにすると購入だけ停止し、所持済みアイテムの使用には影響しません。',
            ]
        );

        app(GameSettingService::class)->flush();
    }

    public function setVisible(string $itemKey, bool $visible): void
    {
        $item = config("adventure_support.items.{$itemKey}");
        if (!is_array($item)) {
            throw new \InvalidArgumentException("Unknown adventure support item: {$itemKey}");
        }

        GameSetting::updateOrCreate(
            ['setting_key' => $this->visibilitySettingKey($itemKey)],
            [
                'label' => "{$item['name']} 表示状態",
                'value' => $visible ? '1' : '0',
                'value_type' => 'boolean',
                'description' => '補給商会の商品表示ON/OFF。OFFにすると商品一覧から非表示になり、購入もできません。',
            ]
        );

        app(GameSettingService::class)->flush();
    }

    public function setCampaign(string $itemKey, ?int $price, ?string $startsAt, ?string $endsAt): void
    {
        $item = config("adventure_support.items.{$itemKey}");
        if (!is_array($item)) {
            throw new \InvalidArgumentException("Unknown adventure support item: {$itemKey}");
        }

        $normalizedStartsAt = $this->normalizeDateTime($startsAt);
        $normalizedEndsAt = $this->normalizeDateTime($endsAt);
        if ($normalizedStartsAt && $normalizedEndsAt && $normalizedStartsAt->gt($normalizedEndsAt)) {
            throw new \InvalidArgumentException('キャンペーン終了日時は開始日時より後にしてください。');
        }

        $settings = [
            'price' => [
                'value' => $price !== null && $price > 0 ? (string) $price : '',
                'label' => "{$item['name']} キャンペーン価格",
                'description' => '補給商会商品のキャンペーン価格。空欄または0ならキャンペーン価格なし。',
                'value_type' => 'integer',
            ],
            'starts_at' => [
                'value' => $normalizedStartsAt?->format('Y-m-d H:i') ?? '',
                'label' => "{$item['name']} キャンペーン開始",
                'description' => '補給商会商品のキャンペーン開始日時（日本時間）。空欄なら即時開始扱い。',
                'value_type' => 'string',
            ],
            'ends_at' => [
                'value' => $normalizedEndsAt?->format('Y-m-d H:i') ?? '',
                'label' => "{$item['name']} キャンペーン終了",
                'description' => '補給商会商品のキャンペーン終了日時（日本時間）。空欄なら終了なし。',
                'value_type' => 'string',
            ],
        ];

        foreach ($settings as $field => $setting) {
            GameSetting::updateOrCreate(
                ['setting_key' => $this->campaignSettingKey($itemKey, $field)],
                $setting
            );
        }

        app(GameSettingService::class)->flush();
    }

    public function clearCampaign(string $itemKey): void
    {
        $this->setCampaign($itemKey, null, null, null);
    }

    public function settingKey(string $itemKey): string
    {
        return self::KEY_PREFIX . $itemKey;
    }

    public function visibilitySettingKey(string $itemKey): string
    {
        return self::VISIBILITY_KEY_PREFIX . $itemKey;
    }

    public function campaignSettingKey(string $itemKey, string $field): string
    {
        return self::CAMPAIGN_KEY_PREFIX . $itemKey . '.' . $field;
    }

    public function campaignFor(string $itemKey, ?array $item = null): array
    {
        $item ??= config("adventure_support.items.{$itemKey}");
        if (!is_array($item)) {
            return $this->emptyCampaign();
        }

        $settings = app(GameSettingService::class);
        $price = $settings->getInt($this->campaignSettingKey($itemKey, 'price'), 0);
        $startsAt = $this->normalizeDateTime($settings->getString($this->campaignSettingKey($itemKey, 'starts_at'), ''));
        $endsAt = $this->normalizeDateTime($settings->getString($this->campaignSettingKey($itemKey, 'ends_at'), ''));
        $now = CarbonImmutable::now('Asia/Tokyo');
        $scheduled = $price > 0 && ($startsAt !== null || $endsAt !== null);
        $active = $scheduled
            && (!$startsAt || $now->gte($startsAt))
            && (!$endsAt || $now->lte($endsAt));

        return [
            'price' => $price > 0 ? $price : null,
            'starts_at' => $startsAt?->format('Y-m-d H:i'),
            'ends_at' => $endsAt?->format('Y-m-d H:i'),
            'starts_at_input' => $startsAt?->format('Y-m-d\TH:i') ?? '',
            'ends_at_input' => $endsAt?->format('Y-m-d\TH:i') ?? '',
            'scheduled' => $scheduled,
            'active' => $active,
            'status_label' => $this->campaignStatusLabel($scheduled, $active, $startsAt, $endsAt, $now),
        ];
    }

    private function statusFor(string $itemKey, array $item): array
    {
        return [
            'key' => $itemKey,
            'setting_key' => $this->settingKey($itemKey),
            'visibility_setting_key' => $this->visibilitySettingKey($itemKey),
            'name' => (string) ($item['name'] ?? $itemKey),
            'category' => (string) ($item['category'] ?? 'その他'),
            'description' => (string) ($item['description'] ?? ''),
            'price' => (int) ($item['price'] ?? 0),
            'currency' => ($item['currency'] ?? 'kiseki') === 'gold' ? 'gold' : 'kiseki',
            'default_enabled' => $this->defaultEnabled($item),
            'enabled' => $this->isEnabled($itemKey, $item),
            'default_visible' => $this->defaultVisible($item),
            'visible' => $this->isVisible($itemKey, $item),
            'campaign' => $this->campaignFor($itemKey, $item),
            'effect_type' => $item['effect_type'] ?? null,
        ];
    }

    private function defaultEnabled(array $item): bool
    {
        return !((bool) ($item['sale_suspended'] ?? false));
    }

    private function defaultVisible(array $item): bool
    {
        return !((bool) ($item['hidden'] ?? false));
    }

    private function normalizeDateTime(?string $value): ?CarbonImmutable
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return CarbonImmutable::parse($value, 'Asia/Tokyo');
    }

    private function emptyCampaign(): array
    {
        return [
            'price' => null,
            'starts_at' => null,
            'ends_at' => null,
            'starts_at_input' => '',
            'ends_at_input' => '',
            'scheduled' => false,
            'active' => false,
            'status_label' => '未設定',
        ];
    }

    private function campaignStatusLabel(bool $scheduled, bool $active, ?CarbonImmutable $startsAt, ?CarbonImmutable $endsAt, CarbonImmutable $now): string
    {
        if (!$scheduled) {
            return '未設定';
        }

        if ($active) {
            return '開催中';
        }

        if ($startsAt && $now->lt($startsAt)) {
            return '開始前';
        }

        if ($endsAt && $now->gt($endsAt)) {
            return '終了済み';
        }

        return '待機中';
    }
}
