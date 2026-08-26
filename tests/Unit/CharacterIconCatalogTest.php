<?php

namespace Tests\Unit;

use App\Models\Character;
use App\Services\CharacterIconSetService;
use App\Support\CharacterIconCatalog;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class CharacterIconCatalogTest extends TestCase
{
    public function test_new_character_icons_are_selectable_through_267(): void
    {
        $paths = CharacterIconCatalog::paths();

        $this->assertContains('/images/chara/chara_156.webp', $paths);
        $this->assertContains('/images/chara/chara_267.webp', $paths);
        $this->assertTrue(CharacterIconCatalog::isSelectable('/images/chara/chara_267.webp'));
        $this->assertSame('/images/chara/chara_267.webp', CharacterIconCatalog::normalize('images/chara/chara_267.webp'));
    }

    public function test_character_icons_after_267_remain_unselectable(): void
    {
        $this->assertNotContains('/images/chara/chara_268.webp', CharacterIconCatalog::paths());
        $this->assertSame(CharacterIconCatalog::DEFAULT_ICON, CharacterIconCatalog::normalize('/images/chara/chara_268.webp'));
    }

    public function test_standard_icon_resolves_optional_four_pose_paths_with_safe_fallbacks(): void
    {
        $originalPublicPath = public_path();
        $temporaryPublicPath = storage_path('framework/testing/character-icon-poses-'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($temporaryPublicPath.'/images/chara/poses/chara_001');
        File::put($temporaryPublicPath.'/images/chara/chara_001.webp', 'legacy normal');
        File::put($temporaryPublicPath.'/images/chara/poses/chara_001/01_normal.webp', 'normal pose');
        File::put($temporaryPublicPath.'/images/chara/poses/chara_001/02_victory.webp', 'victory pose');
        File::put($temporaryPublicPath.'/images/chara/poses/chara_001/03_battle.webp', 'battle pose');
        $this->app->usePublicPath($temporaryPublicPath);

        try {
            $character = new Character(['icon_path' => '/images/chara/chara_001.webp']);

            $this->assertSame([
                'normal' => '/images/chara/poses/chara_001/01_normal.webp',
                'battle' => '/images/chara/poses/chara_001/03_battle.webp',
                'victory' => '/images/chara/poses/chara_001/02_victory.webp',
                'defeat' => '/images/chara/poses/chara_001/01_normal.webp',
            ], app(CharacterIconSetService::class)->resolvedPaths($character));
            $this->assertStringContainsString(
                '/images/chara/poses/chara_001/01_normal.webp?v=',
                CharacterIconCatalog::versionedAsset('/images/chara/chara_001.webp'),
            );
        } finally {
            $this->app->usePublicPath($originalPublicPath);
            File::deleteDirectory($temporaryPublicPath);
        }
    }

    public function test_chara_143_has_complete_128px_four_pose_assets(): void
    {
        $character = new Character(['icon_path' => '/images/chara/chara_143.webp']);
        $paths = app(CharacterIconSetService::class)->resolvedPaths($character);

        $this->assertSame([
            'normal' => '/images/chara/poses/chara_143/01_normal.webp',
            'battle' => '/images/chara/poses/chara_143/03_battle.webp',
            'victory' => '/images/chara/poses/chara_143/02_victory.webp',
            'defeat' => '/images/chara/poses/chara_143/04_defeat.webp',
        ], $paths);

        foreach ($paths as $path) {
            $absolutePath = public_path(ltrim($path, '/'));
            $this->assertFileExists($absolutePath);
            [$width, $height] = array_slice(getimagesize($absolutePath), 0, 2);
            $this->assertSame(128, $width);
            $this->assertSame(128, $height);
        }
    }

    public function test_chara_004_and_267_have_complete_128px_four_pose_assets(): void
    {
        foreach ([4, 267] as $number) {
            $iconPath = sprintf('/images/chara/chara_%03d.webp', $number);
            $paths = CharacterIconCatalog::pathsForStandardIcon($iconPath);

            $this->assertNotNull($paths);
            $this->assertSame($paths, app(CharacterIconSetService::class)->resolvedPaths(
                new Character(['icon_path' => $iconPath]),
            ));

            foreach ($paths as $scene => $path) {
                $absolutePath = public_path(ltrim($path, '/'));

                $this->assertFileExists($absolutePath, "{$iconPath} の {$scene} 画像がありません。");
                $imageSize = getimagesize($absolutePath);
                $this->assertIsArray($imageSize, "{$iconPath} の {$scene} 画像を読み取れません。");
                $this->assertSame(128, $imageSize[0]);
                $this->assertSame(128, $imageSize[1]);
                $this->assertSame('image/webp', $imageSize['mime'] ?? null);
            }
        }
    }

    public function test_new_standard_four_pose_icons_are_permanently_selectable_and_visible(): void
    {
        $numbers = [
            9, 33, 36, 37, 53, 65, 75, 97, 102, 104, 105, 116,
            142, 153, 154, 157, 159, 163, 165, 166, 168, 169, 171,
        ];
        $selectablePaths = CharacterIconCatalog::paths();

        foreach ($numbers as $number) {
            $iconPath = sprintf('/images/chara/chara_%03d.webp', $number);
            $poseDirectory = sprintf('/images/chara/poses/chara_%03d', $number);
            $paths = CharacterIconCatalog::pathsForStandardIcon($iconPath);

            $this->assertContains($iconPath, $selectablePaths);
            $this->assertTrue(CharacterIconCatalog::isSelectable($iconPath));
            $this->assertNotNull($paths);
            $this->assertSame($paths, app(CharacterIconSetService::class)->resolvedPaths(
                new Character(['icon_path' => $iconPath]),
            ));
            $this->assertStringContainsString(
                $poseDirectory.'/01_normal.webp?v=',
                CharacterIconCatalog::versionedAsset($iconPath),
            );

            foreach ($paths as $scene => $path) {
                $absolutePath = public_path(ltrim($path, '/'));

                $this->assertFileExists($absolutePath, "{$iconPath} の {$scene} 画像がありません。");
                $imageSize = getimagesize($absolutePath);
                $this->assertIsArray($imageSize, "{$iconPath} の {$scene} 画像を読み取れません。");
                $this->assertSame(128, $imageSize[0]);
                $this->assertSame(128, $imageSize[1]);
                $this->assertSame('image/webp', $imageSize['mime'] ?? null);
            }
        }
    }

    public function test_configured_exclusive_icon_paths_are_versioned_but_not_publicly_selectable(): void
    {
        $path = '/images/chara/exclusive/exclusive_000/01_normal.webp';

        $this->assertSame($path, CharacterIconCatalog::normalize($path));
        $this->assertTrue(CharacterIconCatalog::isExclusive($path));
        $this->assertFalse(CharacterIconCatalog::isSelectable($path));
        $this->assertNotContains($path, CharacterIconCatalog::paths());
        $this->assertStringContainsString($path.'?v=', CharacterIconCatalog::versionedAsset($path));
    }

    public function test_each_configured_exclusive_icon_set_has_four_supported_square_assets(): void
    {
        $supportedSizes = config('character_icon_sets.asset_sizes.supported', []);

        $this->assertContains(128, $supportedSizes);

        foreach (array_keys(config('character_icon_sets.sets', [])) as $setKey) {
            $paths = CharacterIconCatalog::pathsForSet((string) $setKey);
            $setSize = null;

            $this->assertNotNull($paths, "限定アイコンセット {$setKey} の設定が不正です。");
            $this->assertSame(CharacterIconCatalog::SCENES, array_keys($paths));

            foreach ($paths as $path) {
                $absolutePath = public_path(ltrim($path, '/'));
                $this->assertFileExists($absolutePath);
                [$width, $height] = array_slice(getimagesize($absolutePath), 0, 2);

                $this->assertSame($width, $height, "限定アイコン {$path} は正方形で配置してください。");
                $this->assertContains(
                    $width,
                    $supportedSizes,
                    sprintf(
                        '限定アイコン %s は対応サイズ（%spx）で配置してください。',
                        $path,
                        implode('px / ', $supportedSizes)
                    )
                );
                $setSize ??= $width;
                $this->assertSame(
                    $setSize,
                    $width,
                    "限定アイコンセット {$setKey} の4画像は同じサイズで配置してください。"
                );
            }
        }
    }
}
