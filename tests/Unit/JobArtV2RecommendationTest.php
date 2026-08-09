<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\JobArtV2LoadoutPresenter;
use Tests\TestCase;

class JobArtV2RecommendationTest extends TestCase
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
        ]);
    }

    public function test_supported_jobs_receive_the_three_read_only_battle_style_explanations(): void
    {
        $presenter = app(JobArtV2LoadoutPresenter::class);

        foreach ([
            24 => '星印',
            53 => '星印',
            61 => '冥蝕',
            62 => '竜気',
            64 => '狩猟印',
            65 => '照準',
            85 => '星印',
        ] as $jobId => $resourceName) {
            $styles = $presenter->recommendationsForCurrentJob($jobId, $this->trustedChain($jobId));

            $this->assertSame(['finisher', 'cycle', 'counter'], array_column($styles, 'key'));
            $this->assertSame(['決着型', '循環型', '対策型'], array_column($styles, 'name'));
            $this->assertStringContainsString($resourceName, $styles[0]['priority_note']);
            $this->assertStringContainsString($resourceName, $styles[1]['priority_note']);
            $this->assertSame(['始動', '展開', '奥義'], array_column($styles[0]['steps'], 'role_label'));
            $this->assertSame(['展開', '始動', '奥義'], array_column($styles[1]['steps'], 'role_label'));
            $this->assertSame('条件戦技', $styles[2]['steps'][0]['role_label']);
            $this->assertNull($styles[2]['steps'][0]['art_name']);
        }

        $command = $presenter->recommendationsForCurrentJob(69, $this->trustedChain(69));
        $this->assertStringContainsString('通常攻撃や現在職技で指揮点を貯め', $command[0]['description']);
        $this->assertStringContainsString('通常攻撃や現在職技で指揮点を補充', $command[1]['description']);
        $this->assertSame(['通常攻撃／現在職技', '奥義'], array_column($command[0]['steps'], 'role_label'));
        $this->assertSame(['展開', '通常攻撃／現在職技', '奥義'], array_column($command[1]['steps'], 'role_label'));
    }

    public function test_finisher_cycle_and_counter_copy_matches_the_measured_priority_behavior(): void
    {
        $styles = app(JobArtV2LoadoutPresenter::class)
            ->recommendationsForCurrentJob(53, $this->trustedChain(53));
        $copy = json_encode($styles, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->assertSame('始動戦技を重ねてリソースを温存し、強力な奥義を狙う戦型です。', $styles[0]['description']);
        $this->assertStringContainsString('展開戦技は温存されやすい', $copy);
        $this->assertStringContainsString('条件成立時は奥義が優先', $styles[0]['priority_note']);
        $this->assertStringNotContainsString('始動→展開→奥義', $copy);

        $this->assertSame('リソースを展開戦技へ積極的に使い、Rank5を繰り返して戦う戦型です。', $styles[1]['description']);
        $this->assertStringContainsString('星印4pt以上ある時は展開を先に使用', $styles[1]['priority_note']);
        $this->assertStringContainsString('Rank9は狙いにくい', $copy);

        $this->assertSame('条件付き継承戦技を始動戦技より前に置き、必要な場面だけ割り込ませる戦型です。', $styles[2]['description']);
        $this->assertStringContainsString('条件が成立した時だけ前方の継承戦技が先', $styles[2]['priority_note']);
    }

    public function test_job_specific_copy_uses_only_trusted_prototype_metadata(): void
    {
        $presenter = app(JobArtV2LoadoutPresenter::class);

        $priest = $presenter->recommendationsForCurrentJob(24, [
            ...$this->trustedChain(24),
            $this->skill(24, 3, '推測してはいけない条件技'),
        ]);
        $sage = $presenter->recommendationsForCurrentJob(53, $this->trustedChain(53));
        $lancer = $presenter->recommendationsForCurrentJob(62, $this->trustedChain(62));
        $starPriest = $presenter->recommendationsForCurrentJob(85, $this->trustedChain(85));

        $this->assertStringNotContainsString(
            '推測してはいけない条件技',
            json_encode($priest, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
        $this->assertStringContainsString('星光の場を延長', $sage[1]['job_note']);
        $this->assertStringContainsString('50%DEF貫通', $lancer[0]['job_note']);
        $this->assertStringContainsString('35%DEF貫通', $lancer[1]['job_note']);
        $this->assertStringContainsString('現在の場を2ラウンド固定', $starPriest[1]['job_note']);
        $this->assertStringContainsString('旋律の副場', $starPriest[0]['job_note']);
    }

    public function test_flag_off_and_unsupported_jobs_do_not_receive_recommendations(): void
    {
        $presenter = app(JobArtV2LoadoutPresenter::class);

        $this->assertSame([], $presenter->recommendationsForCurrentJob(90, $this->trustedChain(90)));

        config(['battle.job_art_v2.loadout_v2' => false]);
        $this->assertSame([], $presenter->recommendationsForCurrentJob(24, $this->trustedChain(24)));
    }

    public function test_recommendation_view_is_collapsible_vertical_and_has_no_apply_or_save_action(): void
    {
        $styles = app(JobArtV2LoadoutPresenter::class)
            ->recommendationsForCurrentJob(62, $this->trustedChain(62));
        $html = view('job-arts.partials.recommended-styles', [
            'recommendedBattleStyles' => $styles,
        ])->render();
        $page = file_get_contents(resource_path('views/job-arts/index.blade.php'));

        $this->assertStringContainsString('<details', $html);
        $this->assertStringContainsString('おすすめ戦型を見る', $html);
        $this->assertStringContainsString('data-job-art-v2-style="finisher"', $html);
        $this->assertStringContainsString('data-job-art-v2-style="cycle"', $html);
        $this->assertStringContainsString('data-job-art-v2-style="counter"', $html);
        $this->assertStringContainsString('space-y-3', $html);
        $this->assertStringNotContainsString('grid-cols-3', $html);
        $this->assertStringNotContainsString('overflow-x', $html);
        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringNotContainsString('<button', $html);
        $this->assertStringNotContainsString('戦型を適用', $html);
        $this->assertStringContainsString('@if($jobArtV2UiEnabled && count($recommendedBattleStyles ?? []) > 0)', $page);
    }

    /** @return array<int, Skill> */
    private function trustedChain(int $jobId): array
    {
        return [
            $this->skill($jobId, 1, "始動戦技 {$jobId}"),
            $this->skill($jobId, 5, "展開戦技 {$jobId}"),
            $this->skill($jobId, 9, "奥義 {$jobId}"),
        ];
    }

    private function skill(int $jobId, int $rank, string $name): Skill
    {
        $skill = new Skill([
            'job_id' => $jobId,
            'name' => $name,
            'skill_type' => 'job_art',
            'learn_rank' => $rank,
            'art_cost' => 1,
        ]);
        $skill->setAttribute('id', ($jobId * 100) + $rank);

        return $skill;
    }
}
