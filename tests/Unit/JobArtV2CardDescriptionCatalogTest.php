<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\JobArtV2CardDescriptionCatalog;
use App\Services\JobArtV2LoadoutPresenter;
use Tests\TestCase;

class JobArtV2CardDescriptionCatalogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'battle.job_art_v2.loadout_v2' => true,
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.hit_resolution' => true,
            'battle.job_art_v2.damage_application' => true,
            'battle.job_art_v2.resources' => true,
            'battle.job_art_v2.fields' => true,
            'battle.job_art_v2.penetration' => true,
            'battle.job_art_v2.penetration_stance' => true,
            'battle.job_art_v2.c_design_prototype' => false,
        ]);
    }

    public function test_catalog_is_the_self_contained_192_art_crown_scope(): void
    {
        $catalog = app(JobArtV2CardDescriptionCatalog::class)->all();
        $masterKeys = collect($this->masterRows())
            ->filter(static fn (array $row): bool => self::inCrownScope((int) $row['job_id']))
            ->map(static fn (array $row): string => (int) $row['job_id'].':'.(int) $row['learn_rank'].':'.trim((string) $row['name']))
            ->sort(SORT_NATURAL)
            ->values()
            ->all();

        $this->assertCount(192, $catalog);
        $this->assertSame($masterKeys, array_keys($catalog));
        $this->assertSame(
            'a2608752bb7d40d2e09ea40f5be0d77fdfde598caeb1502a19f5a110873c1ae4',
            hash('sha256', json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        );
        $this->assertNotContains('', array_map('trim', $catalog));
        $this->assertStringContainsString('奥義予告中', implode("\n", $catalog));
        $this->assertStringContainsString('大技予告中', implode("\n", $catalog));
        $this->assertDoesNotMatchRegularExpression(
            '/異系譜.{0,30}(?:消費しない|増えない|増減しない)/u',
            implode("\n", $catalog),
        );
        $this->assertDoesNotMatchRegularExpression(
            '/一時回避状態後|単体大ダメージ|与ダメ(?:ージ)?の一部|固有効果|主\/副能力|[〇◯○�]/u',
            implode("\n", $catalog),
        );

        foreach ($catalog as $key => $description) {
            $sentences = array_values(array_filter(array_map('trim', preg_split('/。/u', $description) ?: [])));
            $this->assertSame($sentences, array_values(array_unique($sentences)), "Duplicate sentence: {$key}");
        }
    }

    public function test_unresolved_workbook_placeholders_are_normalized_to_runtime_values(): void
    {
        $catalog = app(JobArtV2CardDescriptionCatalog::class)->all();

        $this->assertSame(
            '星印を-12し、相手の精神を25%無視して、合計威力315%の魔力ダメージを2回に分けて与える。',
            $catalog['29:9:極大魔法'] ?? null,
        );
        $this->assertSame(
            '照準を-4し、相手の精神を15%無視して、威力185%の魔力ダメージを与える。',
            $catalog['35:5:魔導砲'] ?? null,
        );
        $this->assertSame(
            '聖護を-4し、相手に威力255%の魔力ダメージを与える。その後、4ターンの間、魔力を+25%、精神を+20%する。',
            $catalog['56:5:聖域結界'] ?? null,
        );
        $this->assertSame(
            '剣勢を-12し、相手に威力355%の物理ダメージを与える。'."\n\n".'HITした場合、剣冠の構えを5ターン再展開し、同じ5ターンの間「王冠剣陣」を展開する。王冠剣陣中、剣冠の構えによる直接物理攻撃の受け流し率を35%にする。受け流しに成功した時、相手に威力90%の物理反撃ダメージを1回与える。1つの相手行動につき最大1回。この反撃では剣勢獲得、会心、各種HIT時効果を発生させない。',
            $catalog['60:9:王冠聖剣陣'] ?? null,
        );
    }

    public function test_reviewed_and_runtime_normalized_descriptions_are_frozen_without_tmp_audit_dependencies(): void
    {
        $expected = [
            '17:9:瞬影乱舞' => '狩猟印を-12し、相手に通常攻撃と同じ種類で、合計威力255%のダメージを4回に分けて与える。その後、5ターンの間、通常攻撃が物理なら攻撃を+35%、防御を+20%、魔法なら魔力を+35%、精神を+20%する。',
            '22:9:星霊連弓' => '照準を-12し、相手に合計威力255%の物理ダメージを3回に分けて与える。その後、4ターンの間、相手の防御を-20%、精神を-20%する。',
            '30:5:暗黒剣' => "冥蝕を-4し、自分の攻撃と相手の防御を参照し、相手に威力185%の物理ダメージを与える。与えたダメージの35%分、自分のHPを回復する。その後、反動で最大HPの5%分のダメージを受ける。\n\n奥義予告中の相手、または大技予告中の敵にHITした場合、冥蝕反噬を付与する。対象が次の行動で予告中の奥義または大技を実行すると、解決後に最大HPの5%を非致死で失う。予告中の行動以外を行うと消える。",
            '31:5:ゴールドラッシュ' => '触媒を-4し、相手に合計威力185%の物理ダメージを4回に分けて与える。その後、通常探索勝利時のGold獲得量を2%増やす。',
            '59:1:戦線把握' => '指揮点を+4し、相手に威力205%の物理ダメージを与える。その後、この戦技で成功した行動の種類を記録し、次に使用する指揮系譜戦技の発動率を+15ポイントする。',
            '67:1:金冠錬符' => '触媒を+4し、相手に威力225%の魔力ダメージを与える。その後、相手が次に獲得する系譜リソースの獲得量を半分にする。',
            '67:9:金冠ミダスフィールド' => '触媒を-8し、相手に威力355%の魔力ダメージを与える。その後、相手が次の2回に獲得する系譜リソースの獲得量を半分にする。触媒が8ある場合、セット順より先にこの奥義の発動判定を行う。',
            '68:1:雷冠練気' => '相手に威力225%の物理ダメージを与える。HITした場合、崩しを+4し、相手に崩し印を1段階付与する。',
            '69:1:戦冠指揮' => '指揮点を+4し、相手に威力225%の物理ダメージを与える。次のラウンドの先攻判定で後攻になった場合、その判定を1回だけやり直す。',
        ];
        $catalog = app(JobArtV2CardDescriptionCatalog::class)->all();

        foreach ($expected as $key => $description) {
            $this->assertSame($description, $catalog[$key] ?? null, $key);
        }
    }

    public function test_catalog_uses_exact_natural_key_identity(): void
    {
        $catalog = app(JobArtV2CardDescriptionCatalog::class);
        $skill = $this->masterArt(53, 1, '星読の瞬き');

        $this->assertTrue($catalog->has($skill));
        $skill->name = '星読の瞬き（別名）';
        $this->assertFalse($catalog->has($skill));
        $this->assertNull($catalog->defaultDescription($skill));
    }

    public function test_presenter_uses_exact_description_for_current_and_same_lineage_cards(): void
    {
        $presenter = app(JobArtV2LoadoutPresenter::class);
        $catalog = app(JobArtV2CardDescriptionCatalog::class);
        $current = $this->masterArt(59, 1, '戦線把握', 'current');
        $sameLineage = $this->masterArt(1, 5, '連斬', 'inherited');

        $this->assertSame(
            $catalog->defaultDescription($current),
            $presenter->forArt(59, $current)['card_description'],
        );
        $this->assertSame(
            $catalog->defaultDescription($current),
            $presenter->forArt(59, $current)['display_description'],
        );
        $this->assertSame(
            $catalog->defaultDescription($sameLineage),
            $presenter->forArt(60, $sameLineage)['card_description'],
        );
        $this->assertSame(
            $catalog->defaultDescription($sameLineage),
            $presenter->forArt(60, $sameLineage)['display_description'],
        );
    }

    public function test_presenter_uses_canonical_display_for_cross_lineage_without_changing_resources_off_or_formal_c_design(): void
    {
        $presenter = app(JobArtV2LoadoutPresenter::class);
        $catalog = app(JobArtV2CardDescriptionCatalog::class);
        $crossLineage = $this->masterArt(1, 1, '斬り払い', 'inherited');
        $crossLineage->setAttribute('power', 90);
        $current = $this->masterArt(59, 1, '戦線把握', 'current');
        $crossLineageDisplay = $presenter->forArt(68, $crossLineage);

        $this->assertSame(
            $catalog->defaultDescription($crossLineage),
            $crossLineageDisplay['card_description'],
        );
        $this->assertSame(
            $catalog->defaultDescription($crossLineage),
            $crossLineageDisplay['display_description'],
        );
        $this->assertStringContainsString('-12%', $crossLineageDisplay['display_description']);

        config(['battle.job_art_v2.resources' => false]);
        $this->assertSame(
            $catalog->defaultDescription($current),
            $presenter->forArt(59, $current)['card_description'],
        );

        $loadout = collect([
            $this->masterArt(59, 1, '戦線把握', 'current'),
            $this->masterArt(59, 5, '勝機の戦陣', 'current'),
            $this->masterArt(59, 9, '八陣無双策', 'current'),
            $this->masterArt(48, 1, '先読みの布陣', 'inherited'),
            $this->masterArt(48, 5, '王戦の号令', 'inherited'),
        ]);
        $current = $loadout[0];
        config([
            'battle.job_art_v2.resources' => true,
            'battle.job_art_v2.c_design_prototype' => true,
        ]);
        $this->assertSame(
            $catalog->defaultDescription($current),
            $presenter->forArt(59, $current, $loadout)['card_description'],
        );
    }

    public function test_l_column_counterplay_copy_is_identical_with_c_design_enabled(): void
    {
        $presenter = app(JobArtV2LoadoutPresenter::class);
        $catalog = app(JobArtV2CardDescriptionCatalog::class);
        $loadout = collect([
            $this->masterArt(60, 1, '剣冠の構え', 'current'),
            $this->masterArt(60, 5, '剣冠裁断', 'current'),
            $this->masterArt(60, 9, '王冠聖剣陣', 'current'),
            $this->masterArt(28, 1, '剣気集中', 'inherited'),
            $this->masterArt(28, 5, '無拍子', 'inherited'),
        ]);
        $noTempo = $loadout[4];

        $this->assertStringContainsString(
            '剣勢を-4し、相手に合計威力185%の物理ダメージを2回に分けて与える。',
            $catalog->defaultDescription($noTempo) ?? '',
        );
        $this->assertStringContainsString('大技予告中', $catalog->defaultDescription($noTempo) ?? '');
        $defaultDescription = $presenter->forArt(60, $noTempo)['card_description'];
        $this->assertStringNotContainsString('1.15', $defaultDescription);

        config(['battle.job_art_v2.c_design_prototype' => true]);
        $cDesignDescription = $presenter->forArt(60, $noTempo, $loadout)['card_description'];

        $this->assertStringContainsString('次に受ける予告中の奥義または大技のダメージを20%軽減', $cDesignDescription);
        $this->assertStringContainsString('1.20倍', $cDesignDescription);
        $this->assertStringNotContainsString('1.15', $cDesignDescription);
    }

    /** @return array<int, array<string, mixed>> */
    private function masterRows(): array
    {
        return json_decode(
            file_get_contents(database_path('data/job_arts.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    private static function inCrownScope(int $jobId): bool
    {
        return ($jobId >= 1 && $jobId <= 38) || ($jobId >= 44 && $jobId <= 69);
    }

    private function masterArt(int $jobId, int $rank, string $name, string $origin = ''): Skill
    {
        $row = collect($this->masterRows())->first(static fn (array $candidate): bool => (int) $candidate['job_id'] === $jobId
            && (int) $candidate['learn_rank'] === $rank
            && (string) $candidate['name'] === $name);

        $this->assertIsArray($row, "Job Art {$jobId}:{$rank}:{$name} is missing from the master.");

        $skill = new Skill($row);
        $skill->setAttribute('id', ($jobId * 100) + $rank);
        if ($origin !== '') {
            $skill->setAttribute('job_art_origin', $origin);
        }

        return $skill;
    }
}
