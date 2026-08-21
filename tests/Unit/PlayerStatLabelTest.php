<?php

namespace Tests\Unit;

use App\Support\PlayerStatLabel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlayerStatLabelTest extends TestCase
{
    #[DataProvider('labels')]
    public function test_player_facing_stat_names_are_japanese(string $input, string $expected): void
    {
        $this->assertSame($expected, PlayerStatLabel::for($input));
    }

    /** @return iterable<string, array{string, string}> */
    public static function labels(): iterable
    {
        yield 'attack key' => ['str', '攻撃'];
        yield 'attack abbreviation' => ['ATK', '攻撃'];
        yield 'defense abbreviation' => ['DEF', '防御'];
        yield 'magic abbreviation' => ['MAG', '魔力'];
        yield 'spirit abbreviation' => ['SPR', '精神'];
        yield 'speed abbreviation' => ['SPD', '敏捷'];
        yield 'luck abbreviation' => ['LUK', '運'];
        yield 'hp resource' => ['max_hp', 'HP'];
        yield 'sp resource' => ['max_mp', 'SP'];
    }

    public function test_unknown_internal_name_is_not_invented(): void
    {
        $this->assertSame('unknown_stat', PlayerStatLabel::for('unknown_stat'));
    }
}
