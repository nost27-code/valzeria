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
        yield 'old magic label' => ['魔法', '魔力'];
        yield 'spirit abbreviation' => ['SPR', '精神'];
        yield 'speed abbreviation' => ['SPD', '敏捷'];
        yield 'old speed label' => ['速さ', '敏捷'];
        yield 'luck abbreviation' => ['LUK', '運'];
        yield 'hp resource' => ['max_hp', 'HP'];
        yield 'legacy mp resource' => ['MP', 'SP'];
        yield 'internal mp key' => ['mp', 'SP'];
        yield 'sp resource' => ['max_mp', 'SP'];
    }

    public function test_unknown_internal_name_is_not_invented(): void
    {
        $this->assertSame('unknown_stat', PlayerStatLabel::for('unknown_stat'));
    }

    public function test_legacy_terms_are_normalized_inside_player_copy_without_touching_internal_keys(): void
    {
        $this->assertSame(
            '最大HPとSP、攻撃・防御・魔力・精神・敏捷・運',
            PlayerStatLabel::inText('最大体力とMP、ATK・DEF・MAG・SPR・SPD・LUK'),
        );
        $this->assertSame('物理は防御、魔法は精神で受ける', PlayerStatLabel::inText('物理は物理防御力、魔法は魔法防御力で受ける'));
        $this->assertSame(
            'enemy_atk_down_percent',
            PlayerStatLabel::inText('enemy_atk_down_percent'),
        );
        $this->assertSame(
            '最大SP / 残りSP / SP回復 / mp_base',
            PlayerStatLabel::inText('最大MP / 残りMP / MP回復 / mp_base'),
        );
    }
}
