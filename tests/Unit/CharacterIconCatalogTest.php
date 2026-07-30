<?php

namespace Tests\Unit;

use App\Support\CharacterIconCatalog;
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
