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
}
