<?php

namespace Tests\Unit;

use App\Livewire\JobChange;
use App\Models\Area;
use App\Models\Item;
use App\Models\JobClass;
use App\Models\Skill;
use App\Models\Title;
use App\Models\TowerRunEvent;
use App\Services\ArenaNpcBattleService;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\BattleService;
use App\Services\ChampBattleService;
use App\Services\JobArtV2CardDescriptionCatalog;
use App\Services\JobArtV2ProgressionCatalog;
use App\Services\JobArtV2RoleEffectCatalog;
use App\Services\JobArtV2SlotConditionCatalog;
use App\Services\NamelessEquipmentService;
use App\Services\TowerBattleService;
use App\Support\PlayerStatLabel;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class PlayerFacingStatDisplayContractTest extends TestCase
{
    private const FORBIDDEN_LABELS = [
        'ATK',
        'DEF',
        'MAG',
        'SPR',
        'SPD',
        'LUK',
        'MP',
        '攻撃力',
        '防御力',
        '魔法力',
        '精神力',
        '素早さ',
        '敏捷性',
        '最大体力',
        '残り体力',
    ];

    public function test_battle_stat_change_helpers_use_canonical_player_labels_on_every_legacy_route(): void
    {
        foreach ([BattleService::class, ArenaNpcBattleService::class] as $serviceClass) {
            $state = $this->battleState();
            $service = (new ReflectionClass($serviceClass))->newInstanceWithoutConstructor();
            $method = new ReflectionMethod($serviceClass, 'logStatChange');
            $method->invoke($service, $state, '冒険者', 'ATK', 100, 110, 'DEF', 100, 105, true);

            $this->assertCanonicalOnly((string) end($state->logs), $serviceClass);
            $this->assertStringContainsString('攻撃が10%', (string) end($state->logs));
            $this->assertStringContainsString('防御が5%', (string) end($state->logs));
        }

        $service = (new ReflectionClass(ChampBattleService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(ChampBattleService::class, 'statChangeLog');
        $log = $method->invoke($service, '冒険者', 'MAG', 100, 110, 'SPR', 100, 105, true);

        $this->assertCanonicalOnly((string) $log, ChampBattleService::class);
        $this->assertStringContainsString('魔力が10%', (string) $log);
        $this->assertStringContainsString('精神が5%', (string) $log);
    }

    public function test_player_facing_catalogs_and_presenters_use_canonical_labels(): void
    {
        $job = new JobClass([
            'hp_rate' => 120,
            'mp_rate' => 120,
            'atk_rate' => 120,
            'def_rate' => 120,
            'mag_rate' => 120,
            'spr_rate' => 120,
            'spd_rate' => 120,
            'luck_rate' => 120,
        ]);
        $jobChange = (new ReflectionClass(JobChange::class))->newInstanceWithoutConstructor();
        $growthMethod = new ReflectionMethod(JobChange::class, 'buildHeroGrowthStats');
        $growthLabels = array_column($growthMethod->invoke($jobChange, $job), 'label');

        $this->assertSame(['HP', 'SP', '攻撃', '防御', '魔力', '精神', '敏捷', '運'], $growthLabels);

        $tower = (new ReflectionClass(TowerBattleService::class))->newInstanceWithoutConstructor();
        $stanceText = implode(' ', array_column($tower->stanceChoices(), 'summary'));
        $modifierMethod = new ReflectionMethod(TowerBattleService::class, 'formatModifierTotals');
        $modifierText = implode(' ', array_column($modifierMethod->invoke($tower, [
            'str' => 1,
            'def' => 1,
            'agi' => 1,
            'mag' => 1,
            'spr' => 1,
            'luk' => 1,
        ]), 'text'));
        $conditionText = implode(' ', app(JobArtV2SlotConditionCatalog::class)->labels());
        $cardDescriptionText = implode(' ', app(JobArtV2CardDescriptionCatalog::class)->all());

        $this->assertCanonicalOnly(
            $stanceText.' '.$modifierText.' '.$conditionText.' '.$cardDescriptionText,
            'player-facing catalogs',
        );
        foreach (['攻撃', '防御', '魔力', '精神', '敏捷', '運'] as $label) {
            $this->assertStringContainsString($label, $stanceText.' '.$modifierText.' '.$conditionText);
        }
    }

    public function test_static_player_facing_copy_contains_no_legacy_stat_labels(): void
    {
        $files = [
            resource_path('views/livewire/job-change.blade.php'),
            resource_path('views/livewire/character-create.blade.php'),
            resource_path('views/job-arts/partials/system-guide.blade.php'),
            resource_path('views/battle/partials/job-art-v2-hud.blade.php'),
            config_path('admin_update_summaries.php'),
            config_path('help_content.php'),
        ];

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            $this->assertIsString($contents);
            $this->assertCanonicalOnly($contents, $file);
        }
    }

    public function test_saved_tower_logs_are_normalized_when_presented(): void
    {
        $event = new TowerRunEvent([
            'metadata' => [
                'logs' => [
                    '<span>MPを10回復し、ATKが20%上昇した。</span>',
                    'enemy_atk_down_percent は内部キーのまま',
                ],
            ],
        ]);

        $this->assertSame([
            '<span>SPを10回復し、攻撃が20%上昇した。</span>',
            'enemy_atk_down_percent は内部キーのまま',
        ], $event->playerFacingBattleLogs()->all());
    }

    public function test_job_art_and_admin_stat_displays_need_no_legacy_label_normalization(): void
    {
        $files = [
            resource_path('views/job-arts/index.blade.php'),
            resource_path('views/livewire/admin/battle-simulator.blade.php'),
            resource_path('views/livewire/admin/dungeon-enemy-manager.blade.php'),
            resource_path('views/livewire/admin/job-manager.blade.php'),
            resource_path('views/livewire/admin/region-depth-dungeon-manager.blade.php'),
            resource_path('views/livewire/admin/skill-effect-lab.blade.php'),
            resource_path('views/livewire/admin/tester-manager.blade.php'),
            app_path('Livewire/Admin/JobAffinityChecker.php'),
            app_path('Livewire/Admin/SkillEffectLab.php'),
            app_path('Services/Admin/SkillEffectPreviewService.php'),
        ];

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            $this->assertIsString($contents);
            $this->assertSame($contents, PlayerStatLabel::inText($contents), $file);
        }

        foreach ([
            resource_path('views/livewire/admin/balance-battle-lab.blade.php') => [
                "'str' => '攻'",
                "'def' => '防'",
                "'agi' => '敏'",
                "'mag' => '魔'",
                "'spr' => '精'",
                '<span>攻 {{',
                '<span>防 {{',
                '<span>敏 {{',
                '<span>魔 {{',
                '<span>精 {{',
            ],
            resource_path('views/livewire/admin/item-manager.blade.php') => [
                "'str_bonus' => '攻'",
                "'def_bonus' => '防'",
                "'agi_bonus' => '敏'",
                "'mag_bonus' => '魔'",
                "'spr_bonus' => '精'",
            ],
            resource_path('views/livewire/admin/tester-manager.blade.php') => [
                '腕力',
                '体力',
            ],
        ] as $file => $forbiddenFragments) {
            $contents = file_get_contents($file);
            $this->assertIsString($contents);
            foreach ($forbiddenFragments as $fragment) {
                $this->assertStringNotContainsString($fragment, $contents, $file);
            }
        }
    }

    public function test_job_art_memo_fallbacks_use_the_shared_player_label_normalizer(): void
    {
        foreach ([
            resource_path('views/livewire/job-change.blade.php'),
            resource_path('views/livewire/partials/hero-job-detail.blade.php'),
            resource_path('views/livewire/admin/skill-effect-lab.blade.php'),
            app_path('Livewire/Admin/SkillEffectLab.php'),
            app_path('Services/Admin/JobArtAnalyticsService.php'),
            app_path('Services/Admin/SkillEffectPreviewService.php'),
        ] as $file) {
            $contents = file_get_contents($file);
            $this->assertIsString($contents);
            $this->assertStringContainsString('PlayerStatLabel::inText', $contents, $file);
        }
    }

    public function test_champion_stat_cards_use_magic_and_agility_labels(): void
    {
        $files = [
            resource_path('views/livewire/champ-card.blade.php'),
            resource_path('views/welcome.blade.php'),
            resource_path('views/welcome2.blade.php'),
            resource_path('views/welcome_legacy.blade.php'),
        ];

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            $this->assertIsString($contents);
            $this->assertStringNotContainsString("['魔法'", $contents, $file);
            $this->assertStringNotContainsString("['速さ'", $contents, $file);
            $this->assertStringContainsString("['魔力'", $contents, $file);
            $this->assertStringContainsString("['敏捷'", $contents, $file);
        }
    }

    public function test_generated_player_facing_labels_and_guides_use_canonical_stats(): void
    {
        $skill = new Skill([
            'skill_type' => 'job_art',
            'effect_template' => 'DAMAGE_DEBUFF',
            'power' => 165,
            'duration_turns' => 3,
            'enemy_atk_down_percent' => 10,
            'enemy_mag_down_percent' => 10,
            'enemy_def_down_percent' => 10,
            'enemy_spr_down_percent' => 10,
            'enemy_spd_down_percent' => 10,
            'def_ignore_percent' => 10,
        ]);
        $healSkill = new Skill([
            'skill_type' => 'job_art',
            'effect_template' => 'HEAL',
            'power' => 100,
        ]);

        $texts = [
            ...$skill->jobArtNumericEffectLabels(),
            ...$healSkill->jobArtNumericEffectLabels(),
            ...array_column(NamelessEquipmentService::statOptionsFor('weapon'), 'label'),
            ...array_column(NamelessEquipmentService::statOptionsFor('armor'), 'label'),
            ...$this->catalogEffectTexts(JobArtV2RoleEffectCatalog::class),
            ...$this->catalogEffectTexts(JobArtV2ProgressionCatalog::class),
            ...$this->nestedStrings((array) config('valzeria_guide', [])),
            (string) (new Item(['description' => 'STRとDEFを高める装備']))->description,
            (string) (new JobClass(['description' => 'MAGとSPRが伸びやすい職業']))->description,
            (string) (new Area(['description' => '素早さが高い敵が出る']))->description,
            (string) (new Title(['description' => '攻撃力と敏捷性を極めた証']))->description,
        ];

        $this->assertCanonicalOnly(implode(' ', $texts), 'generated player-facing copy');
    }

    public function test_battle_services_do_not_write_legacy_stat_names_directly_to_logs(): void
    {
        $forbiddenLogFragments = [
            'のATK',
            'のDEF',
            'のMAG',
            'のSPR',
            'のSPD',
            'ATKとMAG',
            '攻撃力と魔法力',
            'DEF低下',
        ];

        foreach ([
            app_path('Services/BattleService.php'),
            app_path('Services/ChampBattleService.php'),
            app_path('Services/ArenaNpcBattleService.php'),
        ] as $file) {
            $contents = file_get_contents($file);
            $this->assertIsString($contents);
            foreach ($forbiddenLogFragments as $fragment) {
                $this->assertStringNotContainsString($fragment, $contents, $file);
            }
        }
    }

    private function battleState(): BattleState
    {
        $stats = [
            'max_hp' => 100,
            'hp' => 100,
            'max_mp' => 50,
            'mp' => 50,
            'str' => 100,
            'def' => 100,
            'agi' => 100,
            'mag' => 100,
            'spr' => 100,
            'luk' => 100,
        ];

        return new BattleState(
            new BattleActor('冒険者', true, $stats),
            new BattleActor('対戦相手', false, $stats),
        );
    }

    private function assertCanonicalOnly(string $text, string $context): void
    {
        foreach (self::FORBIDDEN_LABELS as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $text, $context);
        }
    }

    /** @return list<string> */
    private function catalogEffectTexts(string $catalogClass): array
    {
        $arts = (new ReflectionClass($catalogClass))->getConstant('ARTS');
        $texts = [];
        foreach (is_array($arts) ? $arts : [] as $metadata) {
            foreach ((array) ($metadata['effect_texts'] ?? []) as $text) {
                $texts[] = (string) $text;
            }
        }

        return $texts;
    }

    /** @return list<string> */
    private function nestedStrings(array $values): array
    {
        $strings = [];
        array_walk_recursive($values, static function (mixed $value) use (&$strings): void {
            if (is_string($value)) {
                $strings[] = $value;
            }
        });

        return $strings;
    }
}
