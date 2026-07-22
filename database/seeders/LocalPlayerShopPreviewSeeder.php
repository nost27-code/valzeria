<?php

namespace Database\Seeders;

use App\Models\Character;
use App\Models\PlayerShop;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class LocalPlayerShopPreviewSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('ローカル環境でのみ実行できます。');
            return;
        }

        $shops = [
            ['name' => 'リリィ', 'email' => 'local-shop-preview-lily@valzeria.local', 'shop' => 'リリィの森の店', 'description' => '森で見つけた素材を並べています。', 'icon' => '/images/chara/chara_012.webp', 'type' => 'material', 'banner' => 'forest'],
            ['name' => 'ガロン', 'email' => 'local-shop-preview-garon@valzeria.local', 'shop' => 'ガロン鍛冶商会', 'description' => '銘付き武器を中心に扱います。', 'icon' => '/images/chara/chara_024.webp', 'type' => 'equipment', 'banner' => 'forge'],
            ['name' => 'ミレア', 'email' => 'local-shop-preview-mirea@valzeria.local', 'shop' => '月見の小さな商店', 'description' => '旅の途中で役立つ品を集めました。', 'icon' => '/images/chara/chara_036.webp', 'type' => 'general', 'banner' => 'night'],
        ];

        foreach ($shops as $preview) {
            $user = User::query()->firstOrCreate(
                ['email' => $preview['email']],
                ['name' => $preview['name'], 'password' => Hash::make('local-preview-only')],
            );
            $character = Character::query()->firstOrCreate(
                ['user_id' => $user->id],
                ['name' => $preview['name'], 'icon_path' => $preview['icon']],
            );
            $character->update(['icon_path' => $preview['icon']]);

            PlayerShop::query()->updateOrCreate(
                ['character_id' => $character->id],
                [
                    'name' => $preview['shop'],
                    'description' => $preview['description'],
                    'shop_type' => $preview['type'],
                    'icon_key' => $preview['type'],
                    'banner_key' => $preview['banner'],
                    'status' => 'open',
                    'last_stocked_at' => now(),
                ],
            );
        }
    }
}
