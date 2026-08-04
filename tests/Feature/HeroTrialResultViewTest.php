<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\JobClass;
use Tests\TestCase;

class HeroTrialResultViewTest extends TestCase
{
    public function test_dawn_hero_trial_symbol_asset_is_configured(): void
    {
        $symbolPath = config('hero_trials.released_trials.dawn_hero.symbol_image');

        $this->assertSame('symbol/hero_trial_070.webp', $symbolPath);
        $this->assertFileExists(public_path('images/'.$symbolPath));
    }

    public function test_result_view_accepts_session_serialized_battle_result_arrays(): void
    {
        $character = new Character([
            'name' => 'かんりにん',
            'level' => 48,
        ]);
        $character->setRelation('jobClass', new JobClass(['name' => '剣冠騎士']));

        $outcome = [
            'passed' => false,
            'trial' => [
                'label' => '暁の試練場',
                'hero_job_name' => '暁の勇者',
            ],
            'phase_results' => [
                [
                    'phase' => [
                        'label' => '第1形態・剣相',
                        'name' => '双極天騎アウローラ',
                        'species_keys' => ['spirit', 'soldier'],
                        'type_name' => '物理型',
                        'max_hp' => 70_000,
                        'str' => 6_750,
                        'def' => 5_250,
                        'agi' => 5_000,
                        'mag' => 3_000,
                        'spr' => 4_500,
                        'luk' => 4_500,
                    ],
                    'result' => [
                        'result' => 'defeat',
                        'logs' => ['双極天騎アウローラの攻撃！'],
                        'playerHpBefore' => 30_132,
                        'playerMpBefore' => 10_035,
                        'playerHpAfter' => 0,
                        'playerMpAfter' => 321,
                    ],
                ],
            ],
        ];

        $this->view('hero-trials.result', [
            'outcome' => $outcome,
            'character' => $character,
            'finalStats' => [
                'max_hp' => 30_132,
                'max_mp' => 10_035,
                'str' => 4_000,
                'def' => 3_000,
                'mag' => 2_000,
                'spr' => 1_500,
                'agi' => 2_345,
                'luk' => 1_234,
            ],
            'jobLevel' => 10,
            'characterBattleImagePath' => '/images/chara/chara_001.webp',
            'characterVictoryImagePath' => '/images/chara/chara_001.webp',
            'characterDefeatImagePath' => '/images/chara/chara_001.webp',
        ])
            ->assertSee('双極天騎アウローラ')
            ->assertSee('開始HP')
            ->assertSee('30,132')
            ->assertSee('70,000')
            ->assertSee('2,345')
            ->assertSee('1,234')
            ->assertSee('種族')
            ->assertSee('精霊')
            ->assertSee('人型')
            ->assertSee('双極天騎アウローラの攻撃！', false)
            ->assertSeeInOrder([
                '戦闘開始',
                '双極天騎アウローラの攻撃！',
                '第1形態・剣相で敗退した',
            ], false)
            ->assertDontSee('戦利品を持って帰る')
            ->assertSee('bg-black')
            ->assertSee('border-[#7a2636]')
            ->assertSee('bg-[#5a1320]')
            ->assertSee('321');
    }

    public function test_two_phases_are_rendered_as_one_continuous_battle(): void
    {
        $character = new Character(['name' => 'かんりにん', 'level' => 250]);
        $character->setRelation('jobClass', new JobClass(['name' => '雷拳覇']));
        $basePhase = [
            'name' => '双極天騎アウローラ',
            'species_keys' => ['spirit', 'soldier'],
            'max_hp' => 70_000,
            'str' => 6_750,
            'def' => 5_250,
            'agi' => 5_000,
            'mag' => 3_000,
            'spr' => 4_500,
            'luk' => 4_500,
        ];
        $outcome = [
            'passed' => true,
            'trial' => config('hero_trials.released_trials.dawn_hero'),
            'phase_results' => [
                [
                    'phase' => array_merge($basePhase, [
                        'label' => '第1形態・剣相',
                        'image_path' => 'images/enemy/enemy_723.webp',
                        'type_name' => '物理型',
                    ]),
                    'result' => [
                        'result' => 'victory',
                        'playerHpBefore' => 24_955,
                        'playerMpBefore' => 10_429,
                        'playerHpAfter' => 10_000,
                        'playerMpAfter' => 9_000,
                    ],
                    'display_logs' => ['--- ターン 1 ---', '第一形態の戦闘ログ'],
                ],
                [
                    'phase' => array_merge($basePhase, [
                        'label' => '第2形態・術相',
                        'image_path' => 'images/enemy/enemy_735.webp',
                        'type_name' => '魔法型',
                        'transition_title' => '双極天騎アウローラを倒した！',
                        'transition_body' => 'が、その姿が変わっていく……！！',
                        'str' => 3_000,
                        'def' => 4_500,
                        'mag' => 7_250,
                        'spr' => 5_750,
                    ]),
                    'result' => [
                        'result' => 'victory',
                        'playerHpBefore' => 10_000,
                        'playerMpBefore' => 9_000,
                        'playerHpAfter' => 1_923,
                        'playerMpAfter' => 8_500,
                    ],
                    'display_logs' => ['--- ターン 2 ---', '第二形態の戦闘ログ'],
                ],
            ],
        ];

        $html = view('hero-trials.result', [
            'outcome' => $outcome,
            'character' => $character,
            'finalStats' => [
                'max_hp' => 24_955,
                'max_mp' => 10_429,
                'str' => 7_994,
                'def' => 5_025,
                'mag' => 2_429,
                'spr' => 3_916,
                'agi' => 5_000,
                'luk' => 4_500,
            ],
            'jobLevel' => 10,
            'characterBattleImagePath' => '/images/chara/chara_001.webp',
            'characterVictoryImagePath' => '/images/chara/chara_001.webp',
            'characterDefeatImagePath' => '/images/chara/chara_001.webp',
        ])->render();

        $this->assertSame(1, substr_count($html, '戦闘開始'));
        $this->assertSame(1, substr_count($html, '>VS<'));
        $this->assertSame(1, substr_count($html, 'images/enemy/enemy_723.webp'));
        $this->assertSame(1, substr_count($html, 'images/enemy/enemy_735.webp'));
        $this->assertStringNotContainsString('第1形態・剣相を突破した！', $html);
        $this->assertStringContainsString('が、その姿が変わっていく……！！', $html);
        $this->assertStringContainsString('7,250', $html);
        $this->assertStringContainsString('1,923', $html);
        $this->assertTextAppearsInOrder([
            '第一形態の戦闘ログ',
            '双極天騎アウローラを倒した！',
            'が、その姿が変わっていく……！！',
            '第2形態・術相',
            '第二形態の戦闘ログ',
            '二つの相を打ち破った！',
        ], $html);
    }

    public function test_black_moon_trial_uses_single_battle_copy_and_job_badge(): void
    {
        $character = new Character(['name' => 'かんりにん', 'level' => 250]);
        $character->setRelation('jobClass', new JobClass(['name' => '雷拳覇']));
        $outcome = [
            'passed' => true,
            'trial' => config('hero_trials.released_trials.black_moon_executor'),
            'phase_results' => [[
                'phase' => [
                    'label' => '月蝕相',
                    'name' => '月喰影獣ルナグリム',
                    'species_keys' => ['beast', 'demon'],
                    'image_path' => 'images/enemy/enemy_724.webp',
                    'type_name' => '高速妨害型',
                    'max_hp' => 52_000,
                    'str' => 7_500,
                    'def' => 4_000,
                    'agi' => 8_500,
                    'mag' => 3_200,
                    'spr' => 4_200,
                    'luk' => 6_500,
                ],
                'result' => [
                    'result' => 'victory',
                    'playerHpBefore' => 28_461,
                    'playerMpBefore' => 10_429,
                    'playerHpAfter' => 8_000,
                    'playerMpAfter' => 9_000,
                ],
                'display_logs' => ['月影試練の戦闘ログ'],
            ]],
        ];

        $html = view('hero-trials.result', [
            'outcome' => $outcome,
            'character' => $character,
            'finalStats' => [
                'max_hp' => 28_461,
                'max_mp' => 10_429,
                'str' => 10_947,
                'def' => 5_927,
                'mag' => 2_429,
                'spr' => 4_125,
                'agi' => 7_118,
                'luk' => 5_554,
            ],
            'jobLevel' => 10,
            'characterBattleImagePath' => '/images/chara/chara_001.webp',
            'characterVictoryImagePath' => '/images/chara/chara_001.webp',
            'characterDefeatImagePath' => '/images/chara/chara_001.webp',
        ])->render();

        $this->assertStringContainsString('月蝕高速戦', $html);
        $this->assertStringContainsString('月喰影獣ルナグリムの月影を捉えた！', $html);
        $this->assertStringContainsString('images/jobbadge/jobbadge_071.webp', $html);
        $this->assertStringContainsString('神殿で黒月の執行者を確認する', $html);
        $this->assertStringNotContainsString('二形態連続戦闘', $html);
        $this->assertStringNotContainsString('その姿が変わっていく', $html);
        $this->assertStringNotContainsString('暁の勇者', $html);
    }

    /**
     * @param  list<string>  $needles
     */
    private function assertTextAppearsInOrder(array $needles, string $haystack): void
    {
        $offset = 0;
        foreach ($needles as $needle) {
            $position = strpos($haystack, $needle, $offset);
            $this->assertNotFalse($position, "Expected [{$needle}] after byte offset {$offset}.");
            $offset = $position + strlen($needle);
        }
    }
}
