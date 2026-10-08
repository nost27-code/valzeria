<?php

namespace Tests\Unit;

use App\Services\NamelessEquipmentNameService;
use RuntimeException;
use Tests\TestCase;

class NamelessEquipmentNameServiceTest extends TestCase
{
    public function test_only_the_exact_current_name_is_grandfathered(): void
    {
        app(NamelessEquipmentNameService::class)->assertAllowed('チンコ', 'チンコ');
        app(NamelessEquipmentNameService::class)->assertAllowed('チンコ', '  チンコ  ');
        $this->expectException(RuntimeException::class);
        app(NamelessEquipmentNameService::class)->assertAllowed('ﾁﾝｺ', 'チンコ');
    }

    public function test_obscene_and_abusive_names_are_rejected_across_common_spellings(): void
    {
        $names = ['ちんこの剣', 'チンポ', 'ﾁﾝｺ', 'チ・ン・コ', 'ま ん こ', "ま\u{200B}んこ",
            'おっぱいの盾', 'セックス', 'ｾｯｸｽ', 'セ💠ッ💠ク💠ス', '陰茎', '性器',
            'フェラチオ', 'ﾌｪﾗﾁｵ', 'クンニ', 'パイズリ', 'オナニー', '中出し',
            'レイプ', '強姦', '死ね', 'キチガイ', 'ＦＵＣＫの剣', 'f.u.c.k',
            'fuckblade', 'PORN', 'Penis', 'Vagina', 'SEXの剣', 's e x', 'sex sword',
            'Shit', 'cunt', 'DICK', 'Bitch', 'Asshole'];
        foreach ($names as $name) {
            $rejected = false;
            try {
                app(NamelessEquipmentNameService::class)->assertAllowed($name);
            } catch (RuntimeException $e) {
                $rejected = true;
                $this->assertStringContainsString('使用できない言葉', $e->getMessage(), $name);
            }
            $this->assertTrue($rejected, '禁止語を拒否できませんでした: '.$name);
        }
    }

    public function test_ordinary_fantasy_names_and_latin_substrings_are_allowed(): void
    {
        foreach (['', '星巡りの剣', '神殺しの剣', 'アサシンの短剣', 'フェラルの牙',
            'エロイカ', 'ホモロジー', 'セクスタント', 'Sussex', 'Dickinson', 'Scunthorpe',
            '白銀💠の盾', str_repeat('星', 32)] as $name) {
            app(NamelessEquipmentNameService::class)->assertAllowed($name);
        }
        $this->addToAssertionCount(13);
    }

    public function test_configured_words_use_the_same_comparison_rules(): void
    {
        config(['nameless_equipment_names.blocked_fragments' => ['テスト禁止語'],
            'nameless_equipment_names.blocked_latin_words' => ['blocked']]);
        app(NamelessEquipmentNameService::class)->assertAllowed('キチガイ');
        foreach (['てすと・禁止語', 'ＢＬＯＣＫＥＤの剣'] as $name) {
            $rejected = false;
            try {
                app(NamelessEquipmentNameService::class)->assertAllowed($name);
            } catch (RuntimeException $e) {
                $rejected = true;
                $this->assertStringContainsString('使用できない言葉', $e->getMessage());
            }
            $this->assertTrue($rejected, '設定した禁止語を拒否できませんでした: '.$name);
        }
    }
}
