<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageSourceType;
use App\Services\FieldEvent;
use App\Services\JobArtLineageCatalog;
use App\Services\JobArtV2FieldService;
use App\Services\JobArtV2LoadoutPresenter;
use App\Services\JobArtV2PrototypeCatalog;
use App\Services\JobArtV2ResourceService;
use App\Services\JobArtV2RoleEffectCatalog;
use Tests\TestCase;

class JobArtV2RoleDiversityPresentationTest extends TestCase
{
    /** @var list<array{int, int, string}> */
    private const ROLE_ARTS = [
        [11, 1, '納刀'],
        [13, 1, '闘争本能'],
        [28, 1, '剣気集中'],
        [1, 9, '剣気解放'],
        [11, 9, '刹那雪月花'],
        [13, 9, 'コロッセオブレイク'],
        [4, 5, '狙い撃ち'],
        [18, 5, 'クリティカルショット'],
        [22, 5, 'エレメントアロー'],
        [6, 1, '魔力の火種'],
        [23, 1, '鼓舞の小節'],
        [23, 5, '勇気の旋律'],
        [9, 1, '属性付与'],
        [14, 1, '血潮の咆哮'],
        [2, 1, '挑発撃'],
        [16, 1, '実戦勘'],
        [20, 1, '旅支度'],
        [38, 1, '商聖の助言'],
        [7, 1, 'ヒール'],
        [36, 1, '聖戦の祈り'],
        [8, 5, '幸運の一手'],
        [20, 5, '掘り出し物'],
        [31, 1, '黄金鑑定'],
        [47, 1, '聖薬散布'],
        [8, 9, '大番振る舞い'],
        [20, 9, '大商隊の守護'],
        [31, 9, '王立独占契約'],
        [38, 9, '富国の錬金陣'],
        [47, 9, '神薬アムリタ'],
        [25, 5, '秘薬調合'],
        [38, 5, '王者の秘薬'],
        [50, 1, '聖剣構え'],
        [28, 9, '無双一閃'],
        [50, 9, '光翼クロスブレイク'],
    ];

    /** @var array<string, int> */
    private const SUPPORTED_JOB_BY_LINEAGE = [
        'counter' => 60,
        'field' => 53,
        'eclipse' => 61,
        'pierce' => 62,
        'aim' => 65,
        'guard' => 66,
        'transmute' => 67,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableV2();
    }

    public function test_portable_field_producers_work_for_foreign_lineage_without_foreign_resource_or_self_application(): void
    {
        foreach ([
            [6, '魔力の火種', 'MAGICAL_DAMAGE_BUFF', 'star_light'],
            [23, '鼓舞の小節', 'SELF_BUFF', 'melody'],
        ] as [$jobId, $name, $template, $fieldKey]) {
            [$actor, , $state] = $this->battle(62, 61);
            $actor->configureResource('dragon_force', 12);
            $actor->setResource('dragon_force', 7);
            $skill = $this->art($jobId, 1, $name, $template);
            $actor->jobArtOrigins[(int) $skill->id] = 'inherited';

            $resources = app(JobArtV2ResourceService::class);
            $fields = app(JobArtV2FieldService::class);
            $this->assertNull($resources->eligibilityBlockReason($actor, $skill, $state), $name);
            $resources->beginAction($actor, $state);
            $resources->applyJobArtCast($actor, $state, $skill);

            $this->assertSame($fieldKey, $state->primaryField()?->key, $name);
            $this->assertSame(7, $actor->getResource('dragon_force'), $name);
            $this->assertSame(0, $actor->getResource('star_mark'), $name);
            $this->assertSame(0, $actor->resourceCap('star_mark'), $name);

            if ($fieldKey === 'star_light') {
                $this->assertSame(100, $fields->modifyDamage($actor, $state, 100, DamageSourceType::JOB_ART), $name);
                $resources->beginAction($actor, $state);
                $fields->markSkillAction($actor, $state, $skill);
                $this->assertSame(110, $fields->modifyDamage($actor, $state, 100, DamageSourceType::JOB_ART), $name);
            } else {
                $this->assertSame(35, $fields->activationRate($actor, $state, 35), $name);
                $resources->beginAction($actor, $state);
                $fields->markSkillAction($actor, $state, $skill);
                $this->assertSame(38, $fields->activationRate($actor, $state, 35), $name);
            }
        }
    }

    public function test_bards_field_extension_is_neutral_and_adds_two_rounds_exactly_once(): void
    {
        [$actor, , $state] = $this->battle(53, 61);
        $actor->configureResource('star_mark', 12);
        $actor->setResource('star_mark', 7);
        $skill = $this->art(23, 5, '勇気の旋律', 'SELF_BUFF');
        $actor->jobArtOrigins[(int) $skill->id] = 'inherited';
        $resources = app(JobArtV2ResourceService::class);
        $fields = app(JobArtV2FieldService::class);

        $this->assertSame(
            JobArtV2FieldService::BLOCKED_BY_FIELD,
            $resources->eligibilityBlockReason($actor, $skill, $state),
        );

        $this->assertTrue($fields->deployPrimary($actor, $state, 'star_light', 9_001, 9_001)->applied);
        $this->assertNull($resources->eligibilityBlockReason($actor, $skill, $state));
        $resources->beginAction($actor, $state);
        $result = $resources->applyJobArtCast($actor, $state, $skill);

        $this->assertFalse($result->applied);
        $this->assertSame(7, $actor->getResource('star_mark'));
        $this->assertSame(5, $state->primaryField()?->remainingRounds);
        $this->assertSame(1, $state->primaryField()?->extends);
        $extendedEvents = array_filter(
            $state->fieldEvents(),
            static fn ($event): bool => $event->event === FieldEvent::EXTENDED,
        );
        $this->assertCount(1, $extendedEvents);
    }

    public function test_presenter_exposes_every_exact_role_text_for_same_and_cross_lineage_inheritance_without_changing_master_values(): void
    {
        $presenter = app(JobArtV2LoadoutPresenter::class);
        $roles = app(JobArtV2RoleEffectCatalog::class);
        $lineages = app(JobArtLineageCatalog::class);

        foreach (self::ROLE_ARTS as $index => [$jobId, $rank, $name]) {
            $power = 1_000 + $index;
            $hitCount = 2 + ($index % 3);
            $skill = $this->art($jobId, $rank, $name, power: $power, hitCount: $hitCount);
            $skill->setAttribute('job_art_origin', 'inherited');
            $expectedTexts = $roles->effectTexts($skill);
            $this->assertNotEmpty($expectedTexts, "missing catalog text:{$jobId}:{$rank}:{$name}");
            $lineageKey = (string) ($lineages->forArt($skill)['lineage_key'] ?? '');
            $sameLineageJob = self::SUPPORTED_JOB_BY_LINEAGE[$lineageKey] ?? null;
            $this->assertNotNull($sameLineageJob, "{$jobId}:{$rank}:{$name}");
            $crossLineageJob = $sameLineageJob === 62 ? 61 : 62;

            foreach ([$sameLineageJob, $crossLineageJob] as $currentJobId) {
                $display = $presenter->forArt($currentJobId, $skill);
                $this->assertNotNull($display, "{$currentJobId}:{$name}");
                foreach ($expectedTexts as $text) {
                    $this->assertContains($text, $display['effect_texts'], "{$currentJobId}:{$name}:{$text}");
                }
                $this->assertSame(
                    array_values(array_unique($display['effect_texts'])),
                    $display['effect_texts'],
                    "duplicate:{$currentJobId}:{$name}",
                );
                $this->assertSame($power, $display['effective_power'], "power:{$currentJobId}:{$name}");
                $this->assertSame($power, (int) $skill->power, "master power:{$name}");
                $this->assertSame($hitCount, (int) $skill->hit_count, "master hit count:{$name}");
            }
        }
    }

    public function test_presenter_exposes_role_texts_for_every_exact_art_that_is_supported_as_a_current_job(): void
    {
        $presenter = app(JobArtV2LoadoutPresenter::class);
        $roles = app(JobArtV2RoleEffectCatalog::class);
        $prototypes = app(JobArtV2PrototypeCatalog::class);
        $currentArtCount = 0;

        foreach (self::ROLE_ARTS as [$jobId, $rank, $name]) {
            $skill = $this->art($jobId, $rank, $name);
            $skill->setAttribute('job_art_origin', 'current');
            if (! $prototypes->supportsCurrentJob($jobId)
                || ! $prototypes->isTrustedCurrentJobArt($jobId, $skill)
            ) {
                continue;
            }

            $display = $presenter->forArt($jobId, $skill);
            $this->assertNotNull($display, $name);
            $this->assertSame('current', $display['origin_key'], $name);
            foreach ($roles->effectTexts($skill) as $text) {
                $this->assertContains($text, $display['effect_texts'], "{$name}:{$text}");
            }
            $currentArtCount++;
        }

        $this->assertGreaterThan(0, $currentArtCount);
    }

    public function test_presenter_fails_closed_for_flags_unsupported_jobs_unregistered_names_and_same_named_specials(): void
    {
        $presenter = app(JobArtV2LoadoutPresenter::class);
        $inherited = $this->art(11, 1, '納刀');
        $inherited->setAttribute('job_art_origin', 'inherited');

        config(['battle.job_art_v2.resources' => false]);
        $this->assertSame([], $presenter->forArt(60, $inherited)['effect_texts']);

        $this->enableV2();
        config(['battle.job_art_v2.dynamic_single' => false]);
        $this->assertSame([], $presenter->forArt(60, $inherited)['effect_texts']);

        $this->enableV2();
        $this->assertNull($presenter->forArt(90, $inherited));

        $unregistered = $this->art(11, 1, '納刀・別名');
        $unregistered->setAttribute('job_art_origin', 'inherited');
        $this->assertSame([], $presenter->forArt(60, $unregistered)['effect_texts']);

        $special = $this->art(11, 1, '納刀', skillType: 'special');
        $special->setAttribute('job_art_origin', 'inherited');
        $this->assertNull($presenter->forArt(60, $special));

        config(['battle.job_art_v2.loadout_v2' => false]);
        $this->assertNull($presenter->forArt(60, $inherited));
    }

    private function enableV2(): void
    {
        config([
            'battle.job_art_v2.loadout_v2' => true,
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.hit_resolution' => true,
            'battle.job_art_v2.damage_application' => true,
            'battle.job_art_v2.resources' => true,
            'battle.job_art_v2.fields' => true,
        ]);
    }

    /** @return array{BattleActor, BattleActor, BattleState} */
    private function battle(int $playerJob, int $enemyJob): array
    {
        $player = $this->actor('player', true, $playerJob);
        $enemy = $this->actor('enemy', false, $enemyJob);

        return [$player, $enemy, new BattleState($player, $enemy)];
    }

    private function actor(string $name, bool $isPlayer, int $jobId): BattleActor
    {
        return new BattleActor($name, $isPlayer, [
            'hp' => 1_000,
            'max_hp' => 1_000,
            'mp' => 500,
            'max_mp' => 500,
            'str' => 200,
            'def' => 200,
            'mag' => 200,
            'spr' => 200,
            'agi' => 200,
            'current_job_id' => $jobId,
        ]);
    }

    private function art(
        int $jobId,
        int $rank,
        string $name,
        string $template = 'PHYSICAL_DAMAGE',
        string $skillType = 'job_art',
        int $power = 225,
        int $hitCount = 1,
    ): Skill {
        $skill = new Skill([
            'job_id' => $jobId,
            'learn_rank' => $rank,
            'name' => $name,
            'skill_type' => $skillType,
            'effect_template' => $template,
            'power' => $power,
            'hit_count' => $hitCount,
            'activation_rate' => 100,
            'sp_cost_fixed' => 1,
            'art_cost' => max(1, (int) ceil($rank / 3)),
        ]);
        $skill->setAttribute('id', abs(crc32("{$skillType}:{$jobId}:{$rank}:{$name}")));

        return $skill;
    }
}
