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
            '攻撃本体で1以上のダメージを受ける +1',
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
        $this->assertStringContainsString(
            '能動回避し、成功するとその標的印を1段階消費します',
            $guides['hunt']['trait'],
        );
    }

    public function test_aim_guide_explains_competitive_accuracy_overflow_and_separate_vital_hit(): void
    {
        $aim = app(JobArtV2LineageGuideCatalog::class)->all()['aim'];

        $this->assertStringContainsString('対人戦', $aim['identity']);
        $this->assertStringContainsString('命中率100%を超えた分', $aim['identity']);
        $this->assertStringContainsString('急所命中率', $aim['identity']);
        $this->assertStringContainsString('通常の会心とは別', $aim['trait']);
    }

    public function test_beginner_guide_exposes_exact_direct_and_common_gain_rules(): void
    {
        $guides = app(JobArtV2LineageGuideCatalog::class)->all();

        $this->assertSame([
            'counter' => '始動使用で+4',
            'eclipse' => '原則、始動HITで+4。「血潮の咆哮」「闇の契約」は使用成立で+4',
            'pierce' => '始動使用で+4',
            'hunt' => '始動使用で+4',
            'aim' => '始動使用で+4',
            'guard' => '始動使用で+4',
            'transmute' => '原則、最大HP5%消費と最大SP5%回復が両方成立すると+4。「金冠錬符」はHIT時+4',
            'break' => '原則、始動HITで+4。「練気呼吸」「練気」は使用成立で+4',
            'command' => '原則、始動では増えない。「戦線把握」「戦冠指揮」だけ使用成立で+4',
            'field' => '始動使用で+4。「星冠詠唱」は実際に既存の場を上書きすると追加+2、計+6',
        ], collect($guides)->mapWithKeys(
            fn (array $guide, string $key): array => [$key => $guide['direct_gain']],
        )->all());

        $this->assertSame(
            '通常攻撃HIT +1、攻撃本体で1以上のダメージを受ける +1、受け流し成功 +1。攻撃本体は通常攻撃・職業技・戦技による物理・魔力・複合攻撃です。毒などの継続ダメージ、反射、反撃、自傷、反動、固定・割合ダメージは含みません。多段攻撃は1行動につき1回で、撃破された場合と踏みとどまり発動時は増えません。完全に受け流した攻撃は受け流し成功の+1のみです',
            $guides['counter']['common_gain'],
        );
        $this->assertSame(
            '通常攻撃HIT +4、戦技以外の手番 +1。通常攻撃がHITした場合は計+5',
            $guides['command']['common_gain'],
        );
        $this->assertSame('通常攻撃HIT +1、通常攻撃MISS +2', $guides['aim']['common_gain']);
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
