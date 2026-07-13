<?php

namespace Tests\Unit;

use App\Models\CharacterItem;
use App\Models\Item;
use Tests\TestCase;

class CharacterItemDisplayNameTest extends TestCase
{
    public function test_rank_can_be_excluded_when_it_is_rendered_as_a_separate_badge(): void
    {
        $item = new Item([
            'name' => '鉄の剣',
            'type' => 'weapon',
            'weapon_rank' => 'A',
        ]);
        $characterItem = new CharacterItem(['enhance_level' => 2]);
        $characterItem->setRelation('item', $item);

        $this->assertSame('[A] 鉄の剣 +2', $characterItem->displayName());
        $this->assertSame('鉄の剣 +2', $characterItem->displayName(false));
    }

    public function test_rankless_display_name_keeps_affixes_quality_and_enhancement(): void
    {
        $item = new Item([
            'name' => '鉄の剣',
            'type' => 'weapon',
            'weapon_rank' => 'SS',
        ]);
        $characterItem = new CharacterItem([
            'enhance_level' => 2,
            'affix_quality' => 'excellent',
        ]);
        $characterItem->setRelation('item', $item);

        $this->assertSame('鉄の剣【逸品】 +2', $characterItem->displayName(false));
    }
}
