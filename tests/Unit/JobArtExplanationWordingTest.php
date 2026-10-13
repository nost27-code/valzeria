<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\JobArtV2CardDescriptionCatalog;
use App\Services\JobArtV2LoadoutPresenter;
use App\Services\JobArtV2UltimateCounterplayCatalog;
use Tests\TestCase;

class JobArtExplanationWordingTest extends TestCase
{
    public function test_all_eleven_counterplay_explanations_use_concrete_player_facing_conditions(): void
    {
        $cards = app(JobArtV2CardDescriptionCatalog::class);
        $counterplay = app(JobArtV2UltimateCounterplayCatalog::class);
        $count = 0;
        foreach ($this->rows() as $row) {
            $art = new Skill($row);
            if (! $counterplay->isCounterplayArt($art)) {
                continue;
            }
            $count++;
            foreach ([$cards->defaultDescription($art), $counterplay->effectText($art)] as $text) {
                $this->assertStringContainsString('相手が奥義', $text, $art->name);
                $this->assertStringContainsString('敵モンスター', $text, $art->name);
                $this->assertStringContainsString('予告している', $text, $art->name);
                $this->assertDoesNotMatchRegularExpression('/奥義予告中|大技|専用防御|非致死|封技予約|系譜資源干渉|HIT/u', $text, $art->name);
            }
        }
        $this->assertSame(11, $count);
    }

    public function test_guard_copy_retains_duration_guardability_single_action_and_no_stacking(): void
    {
        $cards = app(JobArtV2CardDescriptionCatalog::class)->all();
        foreach (['15:5:ガーディアンブロウ' => [16, 35], '15:9:不落要塞' => [30, 50]] as $key => [$normal, $warned]) {
            $text = $cards[$key];
            $this->assertStringContainsString('次の自分の行動開始まで', $text);
            $this->assertStringContainsString("受けるダメージを{$normal}%減らす", $text);
            $this->assertStringContainsString('敵モンスターが強い攻撃を予告している時に使う', $text);
            $this->assertStringContainsString('ダメージ軽減の効かない攻撃は対象外', $text);
            $this->assertStringContainsString("次に受けるその攻撃のダメージを{$warned}%減らす", $text);
            $this->assertStringContainsString('通常の軽減とは重ならない', $text);
            $this->assertStringContainsString('複数回当たる攻撃も、まとめて1つの行動', $text);
        }
        $this->assertStringContainsString('能力低下・SP減少・戦技に使う力（聖護など）への妨害を防ぐ', $cards['15:9:不落要塞']);
        $this->assertStringContainsString('HPは1未満にならない', $cards['30:5:暗黒剣']);
        $this->assertStringContainsString('直前に使った行動を封じる効果は発動しない', $cards['54:5:影縫い乱舞']);
    }

    public function test_current_and_inherited_guard_cards_show_the_same_reviewed_copy(): void
    {
        foreach (['loadout_v2', 'dynamic_single', 'hit_resolution', 'damage_application',
            'resources', 'fields', 'penetration', 'penetration_stance', 'ultimate_counterplay'] as $flag) {
            config(['battle.job_art_v2.'.$flag => true]);
        }
        $presenter = app(JobArtV2LoadoutPresenter::class);
        $cards = app(JobArtV2CardDescriptionCatalog::class);
        foreach (array_filter($this->rows(), fn ($row) => $row['job_id'] === 15 && in_array($row['learn_rank'], [5, 9])) as $row) {
            foreach ([false, true] as $cDesign) {
                config(['battle.job_art_v2.c_design_prototype' => $cDesign]);
                foreach ([15 => 'current', 60 => 'inherited'] as $job => $origin) {
                    $art = new Skill($row);
                    $art->setAttribute('job_art_origin', $origin);
                    $display = $presenter->forArt($job, $art, collect([$art]));
                    $this->assertSame($cards->defaultDescription($art), $display['display_description']);
                }
            }
        }
    }

    public function test_nonlethal_hp_explanations_state_the_remaining_hp_in_plain_words(): void
    {
        $cards = app(JobArtV2CardDescriptionCatalog::class)->all();
        foreach (['14:1:血潮の咆哮', '30:5:暗黒剣', '51:5:黒炎斬', '51:9:獄炎ナイトメア', '61:9:黒冠アビスブレイク'] as $key) {
            $this->assertStringNotContainsString('非致死', $cards[$key]);
            $this->assertMatchesRegularExpression('/HP(?:は|が|を)1/u', $cards[$key]);
        }
    }

    private function rows(): array
    {
        return json_decode(file_get_contents(database_path('data/job_arts.json')), true, flags: JSON_THROW_ON_ERROR);
    }
}
