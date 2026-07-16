<?php

namespace App\Support;

use Illuminate\Support\Facades\File;

class CharacterIconCatalog
{
    public const DEFAULT_ICON = '/images/chara/chara_001.webp';
    private const ADMIN_ICON = '/images/chara/admin/chara_admin.webp';
    private const MAX_ICON_NUMBER = 155;

    /**
     * @return array<int, string>
     */
    public static function paths(): array
    {
        $directory = public_path('images/chara');
        if (!is_dir($directory)) {
            return [self::DEFAULT_ICON];
        }

        $paths = collect(File::files($directory))
            ->map(fn ($file): string => '/images/chara/' . $file->getFilename())
            ->filter(fn (string $path): bool => preg_match('/\/chara_\d{3}\.webp\z/', $path) === 1)
            ->filter(fn (string $path): bool => self::numberFromPath($path) <= self::MAX_ICON_NUMBER)
            ->sort()
            ->values()
            ->all();

        return $paths !== [] ? $paths : [self::DEFAULT_ICON];
    }

    public static function normalize(?string $path): string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return self::DEFAULT_ICON;
        }

        $path = '/' . ltrim($path, '/');
        if (preg_match('/\A\/images\/chara\/chara_(\d{1,3})\.webp\z/', $path, $matches) === 1) {
            $number = (int) $matches[1];
            if ($number < 1 || $number > self::MAX_ICON_NUMBER) {
                return self::DEFAULT_ICON;
            }

            return sprintf('/images/chara/chara_%03d.webp', $number);
        }

        return self::DEFAULT_ICON;
    }

    public static function isSelectable(?string $path): bool
    {
        return in_array(self::normalize($path), self::paths(), true);
    }

    public static function versionedAsset(?string $path): string
    {
        $normalized = self::normalize($path);
        $absolutePath = public_path(ltrim($normalized, '/'));
        $version = is_file($absolutePath) ? (string) filemtime($absolutePath) : '1';

        return asset($normalized) . '?v=' . $version;
    }

    public static function adminIconAsset(): string
    {
        $absolutePath = public_path(ltrim(self::ADMIN_ICON, '/'));
        $version = is_file($absolutePath) ? (string) filemtime($absolutePath) : '1';

        return asset(self::ADMIN_ICON) . '?v=' . $version;
    }

    private static function numberFromPath(string $path): int
    {
        if (preg_match('/chara_(\d{3})\.webp\z/', $path, $matches) !== 1) {
            return 0;
        }

        return (int) $matches[1];
    }
}
