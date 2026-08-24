<?php

namespace App\Support;

use App\Enums\SixHeroRoomKey;

final class SixHeroRoomUiCatalog
{
    private const CHAMBERS_IMAGE_PATH = '/images/six_heroes/six_hero_chambers.webp';

    public static function description(SixHeroRoomKey $room): string
    {
        return match ($room) {
            SixHeroRoomKey::SEAL_MAGIC => '魔力に頼らない攻め方が試される間',
            SixHeroRoomKey::SEAL_BLADE => '攻撃に頼らない攻め方が試される間',
            SixHeroRoomKey::BURNING_LIFE => '傷を力へ変え、耐久と回復を競う間',
            SixHeroRoomKey::DIVINE_SPEED => '高い敏捷で先手と火力を極める間',
            SixHeroRoomKey::REVERSE_TIME => '低い敏捷を武器にして戦う間',
            SixHeroRoomKey::MIRACLE => '運を攻めの力へ変える間',
        };
    }

    public static function accentClasses(SixHeroRoomKey $room): string
    {
        return match ($room) {
            SixHeroRoomKey::SEAL_MAGIC => 'border-violet-300 bg-violet-50',
            SixHeroRoomKey::SEAL_BLADE => 'border-rose-300 bg-rose-50',
            SixHeroRoomKey::BURNING_LIFE => 'border-orange-300 bg-orange-50',
            SixHeroRoomKey::DIVINE_SPEED => 'border-cyan-300 bg-cyan-50',
            SixHeroRoomKey::REVERSE_TIME => 'border-indigo-300 bg-indigo-50',
            SixHeroRoomKey::MIRACLE => 'border-amber-300 bg-amber-50',
        };
    }

    /** @return array{x: int, y: int} */
    public static function chamberPosition(SixHeroRoomKey $room): array
    {
        return match ($room) {
            SixHeroRoomKey::BURNING_LIFE => ['x' => 50, 'y' => 24],
            SixHeroRoomKey::SEAL_MAGIC => ['x' => 20, 'y' => 35],
            SixHeroRoomKey::DIVINE_SPEED => ['x' => 80, 'y' => 35],
            SixHeroRoomKey::MIRACLE => ['x' => 15, 'y' => 69],
            SixHeroRoomKey::REVERSE_TIME => ['x' => 85, 'y' => 69],
            SixHeroRoomKey::SEAL_BLADE => ['x' => 50, 'y' => 88],
        };
    }

    public static function chambersImageUrl(): string
    {
        $absolutePath = public_path(ltrim(self::CHAMBERS_IMAGE_PATH, '/'));
        $version = is_file($absolutePath) ? (string) filemtime($absolutePath) : '1';

        return asset(self::CHAMBERS_IMAGE_PATH).'?v='.$version;
    }
}
