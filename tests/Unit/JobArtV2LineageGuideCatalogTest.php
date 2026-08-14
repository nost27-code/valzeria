<?php

namespace Tests\Unit;

use App\Services\JobArtV2LineageGuideCatalog;
use Tests\TestCase;

class JobArtV2LineageGuideCatalogTest extends TestCase
{
    public function test_all_ten_lineages_expose_the_trusted_resource_flow_and_characteristics(): void
    {
        $guides = app(JobArtV2LineageGuideCatalog::class)->all();

        $this->assertSame([
            'counter', 'eclipse', 'pierce', 'hunt', 'aim',
            'guard', 'transmute', 'break', 'command', 'field',
        ], array_keys($guides));

        foreach ($guides as $guide) {
            $this->assertSame(12, $guide['max_points']);
            $expectedFlow = $guide['lineage_key'] === 'command'
                ? ['始動は増減なし', '連携で-4', '奥義で-12']
                : ['始動で+4', '連携で-4', '奥義で-12'];
            $this->assertSame($expectedFlow, $guide['base_flow']);
            $this->assertNotSame('', $guide['identity']);
            $this->assertNotSame('', $guide['trait']);
            $this->assertNotSame('', $guide['ultimate']);
            $this->assertStringNotContainsString('ラウンド', implode(' ', [
                $guide['identity'], $guide['trait'], $guide['ultimate'], $guide['inheritance'],
            ]));
        }

        $this->assertSame('剣勢', $guides['counter']['resource_name']);
        $this->assertSame('冥蝕', $guides['eclipse']['resource_name']);
        $this->assertSame('竜気', $guides['pierce']['resource_name']);
        $this->assertSame('狩猟印', $guides['hunt']['resource_name']);
        $this->assertSame('照準', $guides['aim']['resource_name']);
        $this->assertSame('聖護', $guides['guard']['resource_name']);
        $this->assertSame('触媒', $guides['transmute']['resource_name']);
        $this->assertSame('崩し', $guides['break']['resource_name']);
        $this->assertSame('指揮点', $guides['command']['resource_name']);
        $this->assertSame('星印', $guides['field']['resource_name']);
    }

    public function test_lineage_specific_resource_events_are_shown_with_exact_values(): void
    {
        $guides = app(JobArtV2LineageGuideCatalog::class)->all();

        $this->assertSame([
            '通常攻撃HIT +1',
            '物理攻撃を受ける +1',
            '受け流し成功 +1',
        ], $guides['counter']['additional_gains']);
        $this->assertSame(['通常攻撃HIT +1', '実際に自傷 +2'], $guides['eclipse']['additional_gains']);
        $this->assertSame(['通常攻撃HIT +1', '通常攻撃MISS +2'], $guides['aim']['additional_gains']);
        $this->assertSame([
            '通常攻撃HIT +1',
            '実際に1以上軽減 +1',
            '浄化成功 +1',
        ], $guides['guard']['additional_gains']);
        $this->assertSame(['通常攻撃HIT +4', '戦技以外の手番 +1'], $guides['command']['additional_gains']);
        $this->assertStringContainsString('合計+5', $guides['command']['trait']);
    }

    public function test_field_characteristics_reuse_the_field_catalog_values(): void
    {
        $guide = app(JobArtV2LineageGuideCatalog::class)->all()['field'];

        $this->assertSame([
            '星光の場：自分の魔力ダメージ+10%',
            '旋律の場：自分の戦技発動率+3ポイント',
            '聖域の場：自分の回復量+10%',
            '静寂の場：相手のリソース獲得-1',
            '天測の場：自分の命中率+5ポイント、自分のリソース獲得+1',
        ], $guide['field_effects']);
    }

    public function test_lineage_copy_explains_full_cross_job_effects_and_own_resource(): void
    {
        foreach (app(JobArtV2LineageGuideCatalog::class)->all() as $guide) {
            $this->assertStringContainsString('どの職で使っても', $guide['inheritance']);
            $this->assertStringContainsString('効果と威力は変わりません', $guide['inheritance']);
            $this->assertStringContainsString('カード自身の系譜リソース', $guide['inheritance']);
            $this->assertStringNotContainsString('現在の職', $guide['inheritance']);
        }
    }
}
