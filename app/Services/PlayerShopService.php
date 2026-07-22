<?php

namespace App\Services;

use App\Models\Character;
use App\Models\PlayerShop;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlayerShopService
{
    public function isEnabled(): bool
    {
        return (bool) config('features.player_shops_enabled', false);
    }

    public function ensureForCharacter(Character $character): PlayerShop
    {
        return PlayerShop::query()->firstOrCreate(
            ['character_id' => $character->id],
            [
                'name' => Str::limit((string) $character->name, 18, '') . '商店',
                'description' => '商品を販売しています。',
                'shop_type' => 'general',
                'icon_key' => 'general',
                'banner_key' => 'default',
                'status' => 'open',
            ],
        );
    }

    public function assertCanList(Character $character): PlayerShop
    {
        if (! $this->isEnabled()) {
            throw ValidationException::withMessages(['shop' => '個人商店は現在準備中です。']);
        }

        $shop = $this->ensureForCharacter($character);
        if (! $shop->isOpen()) {
            throw ValidationException::withMessages(['shop' => 'この商店は現在営業を停止しています。']);
        }

        return $shop;
    }

    public function update(PlayerShop $shop, array $attributes): PlayerShop
    {
        $name = trim((string) ($attributes['name'] ?? $shop->name));
        if ($name !== $shop->name && $shop->name_changed_at?->gt(now()->subDays(7))) {
            throw ValidationException::withMessages(['name' => '店名は7日に一度だけ変更できます。']);
        }

        $shop->fill([
            'name' => $name,
            'description' => trim((string) ($attributes['description'] ?? $shop->description)),
            'shop_type' => $attributes['shop_type'] ?? $shop->shop_type,
            'icon_key' => $attributes['icon_key'] ?? $shop->icon_key,
            'banner_key' => $attributes['banner_key'] ?? $shop->banner_key,
        ]);
        if ($name !== $shop->getOriginal('name')) $shop->name_changed_at = now();
        $shop->save();

        return $shop;
    }
}
