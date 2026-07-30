<?php

namespace App\Support;

use Illuminate\Support\Facades\File;

class CharacterIconCatalog
{
    public const DEFAULT_ICON = '/images/chara/chara_001.webp';
    public const SCENES = ['normal', 'battle', 'victory', 'defeat'];

    private const ADMIN_ICON = '/images/chara/admin/chara_admin.webp';
    private const MAX_ICON_NUMBER = 267;

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
        if (self::setKeyForPath($path) !== null) {
            return $path;
        }

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

    /**
     * @return array{normal: string, battle: string, victory: string, defeat: string}|null
     */
    public static function pathsForSet(string $setKey): ?array
    {
        $paths = config("character_icon_sets.sets.{$setKey}.paths");
        if (!is_array($paths)) {
            return null;
        }

        $normalized = [];
        foreach (self::SCENES as $scene) {
            $path = '/' . ltrim((string) ($paths[$scene] ?? ''), '/');
            if (preg_match('/\A\/images\/chara\/exclusive\/[a-z0-9_-]+\/[a-z0-9_-]+\.webp\z/', $path) !== 1) {
                return null;
            }
            $normalized[$scene] = $path;
        }

        return $normalized;
    }

    public static function pathForSet(string $setKey, string $scene): ?string
    {
        if (!in_array($scene, self::SCENES, true)) {
            return null;
        }

        return self::pathsForSet($setKey)[$scene] ?? null;
    }

    public static function setKeyForPath(?string $path): ?string
    {
        $path = '/' . ltrim(trim((string) $path), '/');
        foreach (array_keys((array) config('character_icon_sets.sets', [])) as $setKey) {
            $paths = self::pathsForSet((string) $setKey);
            if ($paths !== null && in_array($path, $paths, true)) {
                return (string) $setKey;
            }
        }

        return null;
    }

    public static function isExclusive(?string $path): bool
    {
        return self::setKeyForPath($path) !== null;
    }

    private static function numberFromPath(string $path): int
    {
        if (preg_match('/chara_(\d{3})\.webp\z/', $path, $matches) !== 1) {
            return 0;
        }

        return (int) $matches[1];
    }
}
