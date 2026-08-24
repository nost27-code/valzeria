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

    /** @return array{summary: string, points: list<string>} */
    public static function ruleGuide(SixHeroRoomKey $room): array
    {
        return match ($room) {
            SixHeroRoomKey::SEAL_MAGIC => [
                'summary' => '魔力を参照する攻撃を封じ、別の能力で攻める間です。',
                'points' => [
                    '魔力を参照して威力を計算する攻撃は、その計算時だけ魔力が0扱いになります。',
                    '技の物理・魔法区分や、相手の防御・精神のどちらを参照するかは変わりません。',
                    '複合攻撃や吸収攻撃も、もともと選ばれた参照能力に従い、部屋の効果を受けた後に参照能力を選び直しません。',
                ],
            ],
            SixHeroRoomKey::SEAL_BLADE => [
                'summary' => '攻撃を参照する攻めを封じ、別の能力で戦う間です。',
                'points' => [
                    '攻撃を参照して威力を計算する攻撃は、その計算時だけ攻撃が0扱いになります。',
                    '技の物理・魔法区分や、相手の防御・精神のどちらを参照するかは変わりません。',
                    '複合攻撃や吸収攻撃も、もともと選ばれた参照能力に従い、部屋の効果を受けた後に参照能力を選び直しません。',
                ],
            ],
            SixHeroRoomKey::BURNING_LIFE => [
                'summary' => 'HPを失うほど守りから攻めへ移り変わる、消耗戦の間です。',
                'points' => [
                    '開始時は、攻撃50%・防御と精神140%・受けるHP回復量100%です。',
                    '敵から実際にHPへ通ったダメージと、この間固有の自傷が開始時最大HPの15%に達するごとに段階が1上がります（最大5段階）。バリアで防いだ分や通常の反動は含みません。',
                    '1段階ごとに攻撃は15ポイント上がり、防御・精神と受けるHP回復量は8ポイント下がります。5段階では攻撃125%・防御と精神100%・回復60%です。',
                    '各行動の終了時に、開始時最大HPの2%＋その時点の現在HPの2%を、バリアなどを介さず直接失います。',
                ],
            ],
            SixHeroRoomKey::DIVINE_SPEED => [
                'summary' => '相手を上回る敏捷が、そのまま攻撃の鋭さへつながる間です。',
                'points' => [
                    '戦闘中の強化・弱体を反映した敏捷が相手より高い時、敏捷の優位率に応じて最終ダメージが増えます。',
                    '増加量は敏捷の優位率の40%分で、最大+60%です。',
                    '先手・命中・回避の判定は通常の対人戦と同じです。',
                ],
            ],
            SixHeroRoomKey::REVERSE_TIME => [
                'summary' => '敏捷を抑えた者が先に動き、重い一撃を狙う間です。',
                'points' => [
                    '敏捷が低い側が先手になります。同じ敏捷なら挑戦側が先手です。',
                    '戦闘中の強化・弱体を反映した敏捷が相手より10%低いごとに、最終ダメージが+8%されます（最大+40%）。10%未満の端数は数えません。',
                    '命中・回避の判定は逆転せず、通常の敏捷判定のままです。',
                ],
            ],
            SixHeroRoomKey::MIRACLE => [
                'summary' => '運を攻撃の中心へ置き換えて競う間です。',
                'points' => [
                    '攻撃の計算に使う能力は、技が本来参照する能力にかかわらず、戦闘中の強化・弱体を反映した運の1.25倍になります（端数切り捨て）。',
                    '技の物理・魔法・複合の区分と、相手の防御・精神の参照先は変わりません。',
                    '技に元々ある運による威力加算は重ねず、運を二重には使いません。',
                ],
            ],
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
