<?php

namespace App\Services;

use App\Models\Skill;

/** Authoritative A-D/B1-B2/portability result for the audited 165 arts. */
final class JobArtV2CDesignClassificationCatalog
{
    public const CLASS_A = 'A';
    public const CLASS_B = 'B';
    public const CLASS_C = 'C';
    public const CLASS_D = 'D';

    public const B1 = 'B1';
    public const B2 = 'B2';

    public const PORTABLE = 'portable';
    public const LINEAGE_ONLY = 'lineage_only';
    public const FINISHER_RESTRICTED = 'finisher_restricted';

    /** @var array<string, true> */
    private const B1_ARTS = [
        '10:1:聖盾撃' => true, '14:5:暴走撃' => true, '15:5:ガーディアンブロウ' => true,
        '18:1:マーキング' => true, '19:5:スピリットスティール' => true, '27:1:勇気の灯' => true,
        '28:5:無拍子' => true, '30:1:闇の契約' => true, '30:5:暗黒剣' => true,
        '31:5:ゴールドラッシュ' => true, '32:1:竜槍構え' => true, '32:5:ドラゴンダイブ' => true,
        '33:5:羅刹連撃' => true, '34:5:夢幻殺' => true, '37:1:影追い' => true,
        '37:5:シャドウスナイプ' => true, '44:1:守護の構え' => true, '44:5:聖盾裁き' => true,
        '45:1:魔矢統率' => true, '46:5:祝福の大旋律' => true, '47:5:霊薬の加護' => true,
        '48:1:先読みの布陣' => true, '48:5:王戦の号令' => true, '49:1:高等錬成' => true,
        '49:5:大錬成爆装' => true, '50:5:聖剣烈破' => true, '51:1:黒炎纏い' => true,
        '51:5:黒炎斬' => true, '53:1:星読の瞬き' => true, '53:5:星詠みの光' => true,
        '56:1:聖域の印' => true, '60:5:剣冠裁断' => true,
    ];

    /** @var array<string, true> */
    private const B2_ARTS = [
        '1:1:斬り払い' => true, '1:5:連斬' => true, '2:5:渾身撃' => true,
        '3:1:すり抜け' => true, '3:5:不意打ち' => true, '4:1:足止め矢' => true,
        '5:1:気合拳' => true, '5:5:連打' => true, '6:5:火炎弾' => true,
        '7:5:癒しの祈り' => true, '8:1:金貨投げ' => true, '9:5:魔法剣' => true,
        '10:5:ホーリーブレイド' => true, '11:5:居合斬り' => true, '12:1:敵情分析' => true,
        '12:5:勝利の采配' => true, '13:5:闘技連斬' => true, '15:1:シールドバッシュ' => true,
        '16:5:戦利の一撃' => true, '17:1:煙玉' => true, '17:5:影縫い' => true,
        '19:1:マナピック' => true, '21:1:練気呼吸' => true, '24:1:浄化の光' => true,
        '24:5:セイクリッドライト' => true, '25:1:応急手当' => true, '26:1:錬成火花' => true,
        '27:5:ブレイブヒール' => true, '29:1:魔力循環' => true, '36:5:神罰の槌' => true,
    ];

    public function __construct(private readonly JobArtV2CDesignCatalog $cDesignCatalog) {}

    public function classFor(Skill $skill): string
    {
        if ((int) $skill->learn_rank === 9) {
            return self::CLASS_D;
        }
        $key = JobArtV2DeckRoleResolution::artKey($skill);
        if ($key === '23:5:勇気の旋律') {
            return self::CLASS_C;
        }
        if (isset(self::B1_ARTS[$key]) || isset(self::B2_ARTS[$key])) {
            return self::CLASS_B;
        }

        return self::CLASS_A;
    }

    public function bClassFor(Skill $skill): ?string
    {
        $key = JobArtV2DeckRoleResolution::artKey($skill);
        if (isset(self::B1_ARTS[$key])) {
            return self::B1;
        }
        if (isset(self::B2_ARTS[$key])) {
            return self::B2;
        }

        return null;
    }

    public function portabilityFor(Skill $skill): string
    {
        if ((int) $skill->learn_rank === 9) {
            return self::FINISHER_RESTRICTED;
        }
        if ((int) $skill->learn_rank === 1
            && $this->cDesignCatalog->isPortableRankOne($skill)
        ) {
            return self::PORTABLE;
        }

        return self::LINEAGE_ONLY;
    }
}
