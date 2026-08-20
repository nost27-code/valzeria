<?php

namespace Tests\Unit;

use App\Http\Controllers\JobArtController;
use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\HitResult;
use App\Services\BattleService;
use App\Services\JobArtBattleSupportService;
use App\Services\JobArtV2CardDescriptionCatalog;
use App\Services\JobArtV2CrownBalanceCatalog;
use App\Services\JobArtV2ProgressionService;
use App\Services\JobArtV2PrototypeCatalog;
use App\Services\JobArtV2RoleEffectCatalog;
use App\Services\JobArtV2RoleEffectService;
use App\Services\JobArtV2TimedEffectState;
use App\Support\JobArtEffectCatalog;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

final class JobArtV2ReplacementWave2ATest extends TestCase
{
    /** @var array<string, array{job_id:int,rank:int,name:string,resource:string,cost:int,current:int,inherited:int}> */
    private const ARTS = [
        'field' => ['job_id' => 6, 'rank' => 5, 'name' => '天測の陣', 'resource' => 'star_mark', 'cost' => 4, 'current' => 6, 'inherited' => 53],
        'hunt' => ['job_id' => 17, 'rank' => 9, 'name' => '狩猟の完成', 'resource' => 'hunt', 'cost' => 12, 'current' => 17, 'inherited' => 54],
        'eclipse' => ['job_id' => 19, 'rank' => 9, 'name' => '魂喰らい', 'resource' => 'eclipse', 'cost' => 12, 'current' => 19, 'inherited' => 61],
        'break' => ['job_id' => 33, 'rank' => 9, 'name' => '崩落', 'resource' => 'break', 'cost' => 12, 'current' => 33, 'inherited' => 58],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableV2();
    }

    public function test_approved_replacement_icons_use_canonical_128px_webp_paths(): void
    {
        $icons = [
            ['name' => '天測の陣', 'job_id' => 6, 'rank' => 5, 'file' => 'job_art_006_05.webp'],
            ['name' => '狩猟の完成', 'job_id' => 17, 'rank' => 9, 'file' => 'job_art_017_09.webp'],
            ['name' => '魂喰らい', 'job_id' => 19, 'rank' => 9, 'file' => 'job_art_019_09.webp'],
            ['name' => '崩落', 'job_id' => 33, 'rank' => 9, 'file' => 'job_art_033_09.webp'],
        ];
        $iconPathResolver = new ReflectionMethod(JobArtController::class, 'jobArtIconPath');
        $controller = app(JobArtController::class);

        foreach ($icons as $icon) {
            $artName = $icon['name'];
            $fileName = $icon['file'];
            $path = public_path('images/job_art/'.$fileName);
            $this->assertFileExists($path, $artName);

            $imageSize = getimagesize($path);
            $this->assertIsArray($imageSize, $artName);
            $this->assertSame(128, $imageSize[0], $artName);
            $this->assertSame(128, $imageSize[1], $artName);
            $this->assertSame('image/webp', $imageSize['mime'] ?? null, $artName);

            $resolvedPath = $iconPathResolver->invoke(
                $controller,
                $this->syntheticArt($icon['job_id'], $icon['rank'], $artName),
            );
            $this->assertMatchesRegularExpression(
                '/^images\/job_art\/'.preg_quote($fileName, '/').'\?v=\d+$/',
                (string) $resolvedPath,
                $artName,
            );
        }
    }

    public function test_master_and_catalogs_define_exactly_the_four_approved_replacements(): void
    {
        $rows = $this->masterRows();
        $this->assertCount(282, $rows);

        $actual = [];
        foreach (self::ARTS as $case) {
            $row = $this->masterRow($case['job_id'], $case['rank']);
            $actual[$case['job_id'].':'.$case['rank']] = (string) $row['name'];
        }
        $this->assertSame([
            '6:5' => '天測の陣',
            '17:9' => '狩猟の完成',
            '19:9' => '魂喰らい',
            '33:9' => '崩落',
        ], $actual);

        $descriptions = app(JobArtV2CardDescriptionCatalog::class)->all();
        $this->assertSame(
            "星印を-4し、相手に威力145%の魔力ダメージを与える。その後、天測の場を5ラウンド展開する。この戦技で展開した天測の場は、この攻撃自身には適用されない。\n\n（天測の場：展開した側の命中率を+5ポイントし、系譜リソースを獲得する際の獲得量を+1する。）",
            $descriptions['6:5:天測の陣'] ?? null,
        );
        $this->assertSame(
            '狩猟印を-12し、相手に威力255%の物理ダメージを与える。相手の標的印が2段階以上ある場合、標的印を2段階消費し、最終ダメージを1.50倍にする。',
            $descriptions['17:9:狩猟の完成'] ?? null,
        );
        $this->assertSame(
            '冥蝕を-12し、相手に威力255%の魔力ダメージを与える。与えたダメージの35%分、自分のHPを回復し、相手の現在SPを最大SPの10%分減らす。',
            $descriptions['19:9:魂喰らい'] ?? null,
        );
        $this->assertSame(
            '崩しを-12し、相手に威力315%の物理ダメージを与える。相手の解除可能な強化を1つ解除し、5ターンの間、相手の防御と精神を-25%する。',
            $descriptions['33:9:崩落'] ?? null,
        );
        foreach (['6:5:火炎弾', '17:9:瞬影乱舞', '19:9:ルーン強奪', '33:9:武神降臨'] as $oldIdentity) {
            $this->assertArrayNotHasKey($oldIdentity, $descriptions);
        }

        $crown = (new ReflectionClass(JobArtV2CrownBalanceCatalog::class))->getConstant('ARTS');
        $this->assertCount(95, $crown);
        $this->assertArrayNotHasKey('17:9:狩猟の完成', $crown);
        $this->assertSame([
            'drain_hp_rate' => 0.35,
            'mp_recover_percent' => 0,
            'sp_pressure_rate' => 0.10,
        ], $crown['19:9:魂喰らい'] ?? null);
        $this->assertSame([
            'hit_count' => 1,
            'debuffs' => ['def' => 25, 'spr' => 25],
            'duration' => 5,
        ], $crown['33:9:崩落'] ?? null);
        $this->assertSame(0.30, (float) ($crown['19:5:スピリットスティール']['drain_hp_rate'] ?? 0));

        $prototype = app(JobArtV2PrototypeCatalog::class);
        $this->assertSame('star_light', $prototype->artFieldMetadata($this->syntheticArt(6, 1, '魔力の火種'))['field_key'] ?? null);
        $field = $prototype->artFieldMetadata($this->art(6, 5));
        $this->assertSame('observation', $field['field_key'] ?? null);
        $this->assertSame(5, $field['field_duration_rounds'] ?? null);

        $role = app(JobArtV2RoleEffectCatalog::class);
        $hunt = $role->forArt($this->art(17, 9));
        $this->assertSame('target_hunting_mark_at_least', $hunt['conditional_damage_multiplier']['condition'] ?? null);
        $this->assertSame(2, $hunt['conditional_damage_multiplier']['minimum'] ?? null);
        $this->assertSame(2, $hunt['conditional_damage_multiplier']['consume_target_hunting_marks'] ?? null);
        $this->assertSame(1.50, $hunt['conditional_damage_multiplier']['multiplier'] ?? null);
        $this->assertSame(1, $role->forArt($this->art(33, 9))['remove_positive_effect']['maximum_effects'] ?? null);

        $flavors = json_decode(
            (string) file_get_contents(database_path('data/job_art_flavor_rewrites.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        foreach (self::ARTS as $case) {
            $matches = array_values(array_filter($flavors, static fn (array $row): bool =>
                (int) $row['job_id'] === $case['job_id'] && (int) $row['learn_rank'] === $case['rank']));
            $this->assertCount(1, $matches);
            $this->assertSame($case['name'], $matches[0]['name']);
        }
    }

    public function test_hunt_finisher_consumes_two_target_marks_at_commit_and_multiplies_only_on_success(): void
    {
        foreach ([
            [0, 0, 1_000],
            [1, 1, 1_000],
            [2, 0, 1_500],
            [3, 1, 1_500],
        ] as [$marks, $remaining, $expectedDamage]) {
            [$actor, $target, $state] = $this->battle(17);
            $art = $this->attach($actor, $this->art(17, 9), 'current');
            $this->setHuntingMarks($target, $actor, $marks);
            $execution = $this->beginRoleCast($actor, $target, $state, $art);

            $this->assertSame($remaining, app(JobArtV2ProgressionService::class)->huntingMarkCountFor($target, $actor));
            $this->assertSame($expectedDamage, app(JobArtV2RoleEffectService::class)->modifyJobArtDamage($actor, $state, $execution, 1_000));
            $this->assertSame('PHYSICAL_DAMAGE', (string) $execution->effect_template);
            $this->assertSame('physical', (string) $execution->damage_type);
            $this->assertSame(255, (int) $execution->power);
            $this->assertSame(1, (int) $execution->hit_count);
            $this->assertSame(0, (int) $execution->self_buff_percent);
        }

        foreach ([HitResult::HIT, HitResult::MISS, HitResult::EVADE] as $hitResult) {
            [$actor, $target, $state] = $this->battle(17);
            $art = $this->attach($actor, $this->art(17, 9), 'current');
            $this->setHuntingMarks($target, $actor, 2);
            $this->beginRoleCast($actor, $target, $state, $art);
            app(JobArtV2RoleEffectService::class)->completeJobArtCast($actor, $target, $state, $art, $hitResult);

            $this->assertSame(0, app(JobArtV2ProgressionService::class)->huntingMarkCountFor($target, $actor), $hitResult->value);
        }

        [$actor, $target, $state] = $this->battle(17);
        $art = $this->attach($actor, $this->art(17, 9), 'current');
        $this->setHuntingMarks($target, $actor, 2);
        $first = $this->beginRoleCast($actor, $target, $state, $art);
        $this->assertSame(1_500, app(JobArtV2RoleEffectService::class)->modifyJobArtDamage($actor, $state, $first, 1_000));
        $second = $this->beginRoleCast($actor, $target, $state, $art);
        $this->assertSame(1_000, app(JobArtV2RoleEffectService::class)->modifyJobArtDamage($actor, $state, $second, 1_000));
        $this->setHuntingMarks($target, $actor, 2);
        $third = $this->beginRoleCast($actor, $target, $state, $art);
        $this->assertSame(1_500, app(JobArtV2RoleEffectService::class)->modifyJobArtDamage($actor, $state, $third, 1_000));
    }

    public function test_hunt_finisher_resource_gate_and_existing_job_54_64_mark_consumption_are_unchanged(): void
    {
        $support = app(JobArtBattleSupportService::class);
        [$blocked, $blockedTarget, $blockedState] = $this->battle(17);
        $art = $this->attach($blocked, $this->art(17, 9), 'current');
        $blocked->configureResource('hunt', 12);
        $blocked->setResource('hunt', 11);
        $spBefore = $blocked->mp;
        $support->beginAction($blocked, $blockedState);
        $this->assertFalse($support->consumeAndMarkUse($blocked, $blockedState, $art));
        $this->assertSame(11, $blocked->getResource('hunt'));
        $this->assertSame($spBefore, $blocked->mp);
        $this->assertNull($blockedTarget->existingJobArtV2ProgressionState());

        [$actor, , $state] = $this->battle(17);
        $art = $this->attach($actor, $this->art(17, 9), 'current');
        $actor->configureResource('hunt', 12);
        $actor->setResource('hunt', 12);
        $support->beginAction($actor, $state);
        $this->assertTrue($support->consumeAndMarkUse($actor, $state, $art));
        $this->assertSame(0, $actor->getResource('hunt'));

        foreach ([54, 64] as $jobId) {
            [$hunter, $target, $markState] = $this->battle($jobId);
            $legacyFinisher = $this->attach($hunter, $this->art($jobId, 9), 'current');
            $this->setHuntingMarks($target, $hunter, 2);
            $this->beginRoleCast($hunter, $target, $markState, $legacyFinisher);
            $this->assertSame(0, app(JobArtV2ProgressionService::class)->huntingMarkCountFor($target, $hunter), "job {$jobId}");
        }
    }

    public function test_collapse_is_one_hit_removes_one_buff_for_every_hit_result_and_applies_25_percent_debuffs(): void
    {
        foreach ([HitResult::HIT, HitResult::MISS, HitResult::EVADE] as $hitResult) {
            [$actor, $target, $state] = $this->battle(33);
            $art = $this->attach($actor, $this->art(33, 9), 'current');
            $target->replaceJobArtV2TimedEffect($this->timed('weak', 0.10, 10, true));
            $target->replaceJobArtV2TimedEffect($this->timed('strong', 0.30, 30, true));
            $target->replaceJobArtV2TimedEffect($this->timed('fixed', 0.50, 50, false));
            $execution = $this->beginRoleCast($actor, $target, $state, $art);
            app(JobArtV2RoleEffectService::class)->completeJobArtCast($actor, $target, $state, $art, $hitResult);

            $this->assertSame('DAMAGE_DEBUFF', (string) $execution->effect_template);
            $this->assertSame(315, (int) $execution->power);
            $this->assertSame(1, (int) $execution->hit_count);
            $this->assertSame(25, (int) $execution->enemy_def_down_percent);
            $this->assertSame(25, (int) $execution->enemy_spr_down_percent);
            $this->assertSame(5, (int) $execution->duration_turns);
            $this->assertNull($target->jobArtV2TimedEffect('strong'), $hitResult->value);
            $this->assertNotNull($target->jobArtV2TimedEffect('weak'), $hitResult->value);
            $this->assertNotNull($target->jobArtV2TimedEffect('fixed'), $hitResult->value);
        }

        [$actor, $target, $state] = $this->battle(33);
        $art = $this->attach($actor, $this->art(33, 9), 'current');
        $this->beginRoleCast($actor, $target, $state, $art);
        $beforeLogs = count($state->logs);
        app(JobArtV2RoleEffectService::class)->completeJobArtCast($actor, $target, $state, $art, HitResult::MISS);
        $this->assertSame($beforeLogs, count($state->logs), 'No removal log is emitted when no removable buff exists.');

        [$protectedActor, $protectedTarget, $protectedState] = $this->battle(33);
        $protectedArt = $this->attach($protectedActor, $this->art(33, 9), 'current');
        $protectedTarget->replaceJobArtV2TimedEffect($this->timed('protected', 0.30, 30, true));
        $protectedTarget->jobArtV2ProgressionState()->immutableRhythmCharges = 1;
        $this->beginRoleCast($protectedActor, $protectedTarget, $protectedState, $protectedArt);
        app(JobArtV2RoleEffectService::class)->completeJobArtCast($protectedActor, $protectedTarget, $protectedState, $protectedArt, HitResult::HIT);
        $this->assertNotNull($protectedTarget->jobArtV2TimedEffect('protected'));
        $this->assertSame(0, $protectedTarget->jobArtV2ProgressionState()->immutableRhythmCharges);

        [$debuffer, $debuffTarget, $debuffState] = $this->battle(33);
        $debuffArt = $this->attach($debuffer, $this->art(33, 9), 'current');
        $debuffTarget->replaceJobArtV2TimedEffect(new JobArtV2TimedEffectState(
            key: 'other_debuff',
            statModifiers: ['def' => -0.10],
            appliedRound: 1,
            remainingRounds: 3,
            sourceActionId: 1,
            sourceSkillId: 1,
            removable: false,
            strength: 10,
        ));
        $this->beginRoleCast($debuffer, $debuffTarget, $debuffState, $debuffArt);
        $result = app(JobArtV2RoleEffectService::class)->applyTimedStructuredDebuffs($debuffer, $debuffTarget, $debuffState, $debuffArt);
        $this->assertSame(5, $result['duration_turns'] ?? null);
        $this->assertSame(65, $debuffTarget->effectiveDef());
        $this->assertSame(75, $debuffTarget->effectiveSpr());
        $this->assertCount(2, $debuffTarget->jobArtV2TimedEffects());
    }

    public function test_observation_formation_consumes_four_deploys_for_five_rounds_and_never_self_applies(): void
    {
        $support = app(JobArtBattleSupportService::class);
        foreach ([HitResult::HIT, HitResult::MISS, HitResult::EVADE] as $hitResult) {
            [$actor, $target, $state] = $this->battle(6);
            $art = $this->attach($actor, $this->art(6, 5), 'current');
            $actor->configureResource('star_mark', 12);
            $actor->setResource('star_mark', 4);

            $support->beginAction($actor, $state);
            $this->assertSame(0.0, $support->fieldAccuracyDelta($actor, $state));
            $this->assertTrue($support->consumeAndMarkUse($actor, $state, $art));
            $execution = $support->skillForExecution($actor, $art, $state, $target);
            $support->completeJobArtCast($actor, $state, $art, $hitResult, $target);

            $this->assertSame(0, $actor->getResource('star_mark'), $hitResult->value);
            $this->assertSame('MAGICAL_DAMAGE', (string) $execution->effect_template);
            $this->assertSame('magical', (string) $execution->damage_type);
            $this->assertSame(145, (int) $execution->power);
            $this->assertSame(1, (int) $execution->hit_count);
            $this->assertSame('observation', $state->primaryField()?->key, $hitResult->value);
            $this->assertSame(5, $state->primaryField()?->remainingRounds, $hitResult->value);
            $this->assertSame(0.0, $support->fieldAccuracyDelta($actor, $state), 'The source action keeps its pre-deploy snapshot.');
        }

        [$actor, $target, $state] = $this->battle(6);
        $art = $this->attach($actor, $this->art(6, 5), 'current');
        $actor->configureResource('star_mark', 12);
        $actor->setResource('star_mark', 4);
        $support->beginAction($actor, $state);
        $support->consumeAndMarkUse($actor, $state, $art);
        $support->finishAction($actor, $state);
        $support->beginAction($actor, $state);
        $this->assertSame(5.0, $support->fieldAccuracyDelta($actor, $state));
        $gain = $support->recordNormalAttackResolution($actor, $target, $state, HitResult::HIT);
        $this->assertSame(2, $gain->delta, 'The next eligible resource gain receives observation +1.');

        [$blocked, , $blockedState] = $this->battle(6);
        $blockedArt = $this->attach($blocked, $this->art(6, 5), 'current');
        $blocked->configureResource('star_mark', 12);
        $blocked->setResource('star_mark', 3);
        $support->beginAction($blocked, $blockedState);
        $this->assertFalse($support->consumeAndMarkUse($blocked, $blockedState, $blockedArt));
        $this->assertNull($blockedState->primaryField());

        $prototype = app(JobArtV2PrototypeCatalog::class);
        $this->assertSame('star_light', $prototype->artFieldMetadata($this->syntheticArt(6, 1, '魔力の火種'))['field_key'] ?? null);
        $this->assertSame('silence', $prototype->artFieldMetadata($this->syntheticArt(29, 1, '静寂の帳'))['field_key'] ?? null);
    }

    public function test_soul_eater_is_magical_35_percent_drain_and_hit_only_ten_percent_sp_pressure(): void
    {
        $support = app(JobArtBattleSupportService::class);
        foreach ([HitResult::HIT, HitResult::MISS, HitResult::EVADE] as $hitResult) {
            [$actor, $target, $state] = $this->battle(19, actorOverrides: ['hp' => 900], targetOverrides: ['mp' => 100, 'max_mp' => 101]);
            $art = $this->attach($actor, $this->art(19, 9), 'current');
            $actor->configureResource('eclipse', 12);
            $actor->setResource('eclipse', 12);
            $support->beginAction($actor, $state);
            $this->assertTrue($support->consumeAndMarkUse($actor, $state, $art));
            $execution = $support->skillForExecution($actor, $art, $state, $target);
            $support->completeJobArtCast($actor, $state, $art, $hitResult, $target);
            $support->completeJobArtCast($actor, $state, $art, $hitResult, $target);

            $this->assertSame(0, $actor->getResource('eclipse'));
            $this->assertSame('DRAIN', (string) $execution->effect_template);
            $this->assertSame('magical', (string) $execution->damage_type);
            $this->assertSame(255, (int) $execution->power);
            $this->assertSame(1, (int) $execution->hit_count);
            $this->assertSame(0.35, (float) $execution->drain_hp_rate);
            $this->assertSame(0, (int) $execution->mp_recover_percent);
            $this->assertNull($actor->jobArtV2TimedEffect('canonical_self_buff:19:9'));
            $this->assertSame($hitResult === HitResult::HIT ? 89 : 100, $target->mp, $hitResult->value);
        }

        [$lowSpActor, $lowSpTarget, $lowSpState] = $this->battle(19, actorOverrides: ['hp' => 900], targetOverrides: ['mp' => 7, 'max_mp' => 101]);
        $lowSpArt = $this->attach($lowSpActor, $this->art(19, 9), 'current');
        $lowSpActor->configureResource('eclipse', 12);
        $lowSpActor->setResource('eclipse', 12);
        $support->beginAction($lowSpActor, $lowSpState);
        $support->consumeAndMarkUse($lowSpActor, $lowSpState, $lowSpArt);
        $support->completeJobArtCast($lowSpActor, $lowSpState, $lowSpArt, HitResult::HIT, $lowSpTarget);
        $this->assertSame(0, $lowSpTarget->mp);

        $this->assertDrainHeal(900, 100, 935);
        $this->assertDrainHeal(990, 100, 1_000);
        $this->assertDrainHeal(900, 0, 900);

        $rankFive = app(JobArtV2CrownBalanceCatalog::class)->forArt($this->syntheticArt(19, 5, 'スピリットスティール'));
        $this->assertSame(0.30, (float) ($rankFive['drain_hp_rate'] ?? 0));
    }

    public function test_all_four_arts_keep_the_same_execution_semantics_in_six_contexts_and_both_origins(): void
    {
        $support = app(JobArtBattleSupportService::class);
        foreach (self::ARTS as $key => $case) {
            foreach (['pve', 'boss', 'tower', 'pvp', 'champ', 'arena_npc'] as $battleType) {
                foreach (['current', 'inherited'] as $origin) {
                    $currentJob = $case[$origin];
                    [$actor, $target, $state] = $this->battle($currentJob, $battleType);
                    if ($key === 'eclipse') {
                        $actor->hp = 900;
                    }
                    $art = $this->attach($actor, $this->art($case['job_id'], $case['rank']), $origin);
                    $actor->configureResource($case['resource'], 12);
                    $actor->setResource($case['resource'], $case['cost']);
                    if ($key === 'hunt') {
                        $this->setHuntingMarks($target, $actor, 2);
                    }

                    $label = implode(' / ', [$case['name'], $battleType, $origin]);
                    $this->assertNotNull($support->beginAction($actor, $state), $label);
                    $this->assertTrue($support->consumeAndMarkUse($actor, $state, $art), $label);
                    $execution = $support->skillForExecution($actor, $art, $state, $target);
                    $this->assertSame(0, $actor->getResource($case['resource']), $label);

                    match ($key) {
                        'field' => $this->assertSame(['MAGICAL_DAMAGE', 'magical', 145, 1], $this->executionTuple($execution), $label),
                        'hunt' => $this->assertSame(['PHYSICAL_DAMAGE', 'physical', 255, 1], $this->executionTuple($execution), $label),
                        'eclipse' => $this->assertSame(['DRAIN', 'magical', 255, 1], $this->executionTuple($execution), $label),
                        'break' => $this->assertSame(['DAMAGE_DEBUFF', 'physical', 315, 1], $this->executionTuple($execution), $label),
                    };
                    if ($key === 'hunt') {
                        $this->assertSame(1_500, $support->modifyJobArtDamage($actor, $state, $execution, 1_000), $label);
                    }
                    if ($key === 'field') {
                        $this->assertSame('observation', $state->primaryField()?->key, $label);
                    }

                    $support->completeJobArtCast($actor, $state, $art, HitResult::HIT, $target);
                    if (in_array($key, ['hunt', 'eclipse'], true)) {
                        $this->assertNull($actor->jobArtV2TimedEffect("canonical_self_buff:{$case['job_id']}:{$case['rank']}"), $label);
                    }
                }
            }
        }
    }

    public function test_new_hunting_mark_consumer_fails_closed_when_v2_is_off(): void
    {
        config([
            'battle.job_art_v2.dynamic_single' => false,
            'battle.job_art_v2.hit_resolution' => false,
            'battle.job_art_v2.damage_application' => false,
            'battle.job_art_v2.resources' => false,
            'battle.job_art_v2.fields' => false,
        ]);
        [$actor, $target, $state] = $this->battle(17);
        $art = $this->attach($actor, $this->art(17, 9), 'current');
        $this->setHuntingMarks($target, $actor, 2);
        $sourceActionId = $state->beginSourceAction();
        $service = app(JobArtV2RoleEffectService::class);

        $service->beginAction($actor, $state, $sourceActionId);
        $service->beginJobArtCast($actor, $state, $art);

        $this->assertSame(2, app(JobArtV2ProgressionService::class)->huntingMarkCountFor($target, $actor));
        $this->assertSame(1_000, $service->modifyJobArtDamage($actor, $state, $art, 1_000));
        $this->assertSame([], $state->jobArtV2RoleAction());
    }

    private function enableV2(): void
    {
        config([
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.hit_resolution' => true,
            'battle.job_art_v2.damage_application' => true,
            'battle.job_art_v2.resources' => true,
            'battle.job_art_v2.fields' => true,
            'battle.job_art_v2.normalized_sp' => false,
            'battle.job_art_v2.c_design_prototype' => false,
            'battle.job_art_v2.ultimate_counterplay' => false,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function masterRows(): array
    {
        return json_decode(
            (string) file_get_contents(database_path('data/job_arts.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /** @return array<string, mixed> */
    private function masterRow(int $jobId, int $rank): array
    {
        $matches = array_values(array_filter($this->masterRows(), static fn (array $row): bool =>
            (int) $row['job_id'] === $jobId && (int) $row['learn_rank'] === $rank));
        $this->assertCount(1, $matches, "Master identity {$jobId}:{$rank}");

        return $matches[0];
    }

    private function art(int $jobId, int $rank): Skill
    {
        $row = $this->masterRow($jobId, $rank);
        $power = is_numeric($row['power_hint'] ?? null)
            ? (int) $row['power_hint']
            : (preg_match('/\d+/', (string) ($row['power_hint'] ?? ''), $match) ? (int) $match[0] : 100);
        $template = (string) $row['effect_template'];
        $skill = new Skill(array_replace($row, [
            'power' => $power,
            'power_multiplier' => $power / 100,
            'damage_type' => $template === 'DRAIN'
                ? JobArtEffectCatalog::drainDamageType($row['damage_type'] ?? null)
                : JobArtEffectCatalog::damageType($template),
            'hit_count' => (int) ($row['hit_count'] ?? JobArtEffectCatalog::hitCount($template)),
            'sp_cost_fixed' => (int) ($row['sp_cost_fixed'] ?? match (true) {
                $jobId === 6 && $rank === 5 => 22,
                $jobId === 33 && $rank === 9 => 46,
                default => 42,
            }),
        ]));
        $skill->setAttribute('id', ($jobId * 100) + $rank);

        return $skill;
    }

    private function syntheticArt(int $jobId, int $rank, string $name): Skill
    {
        $skill = new Skill([
            'job_id' => $jobId,
            'learn_rank' => $rank,
            'name' => $name,
            'skill_type' => 'job_art',
            'effect_template' => 'PHYSICAL_DAMAGE',
            'damage_type' => 'physical',
            'power' => 100,
            'power_multiplier' => 1.0,
            'hit_count' => 1,
            'sp_cost_fixed' => 0,
        ]);
        $skill->setAttribute('id', 900_000 + ($jobId * 100) + $rank);

        return $skill;
    }

    private function attach(BattleActor $actor, Skill $skill, string $origin): Skill
    {
        $actor->jobArts = [$skill];
        $actor->jobArtOrigins[(int) $skill->id] = $origin;
        $actor->jobArtRates[(int) $skill->id] = 1.0;

        return $skill;
    }

    /** @param array<string, int|string> $actorOverrides @param array<string, int|string> $targetOverrides */
    private function battle(
        int $currentJob,
        string $battleType = 'pve',
        array $actorOverrides = [],
        array $targetOverrides = [],
    ): array {
        $actor = $this->actor('actor', true, $currentJob, $actorOverrides);
        $target = $this->actor('target', false, 60, $targetOverrides);

        return [$actor, $target, new BattleState($actor, $target, $battleType)];
    }

    /** @param array<string, int|string> $overrides */
    private function actor(string $name, bool $isPlayer, int $jobId, array $overrides = []): BattleActor
    {
        return new BattleActor($name, $isPlayer, array_replace([
            'hp' => 1_000,
            'max_hp' => 1_000,
            'mp' => 1_000,
            'max_mp' => 1_000,
            'str' => 100,
            'def' => 100,
            'agi' => 100,
            'mag' => 100,
            'spr' => 100,
            'luk' => 100,
            'current_job_id' => $jobId,
        ], $overrides));
    }

    private function beginRoleCast(BattleActor $actor, BattleActor $target, BattleState $state, Skill $art): Skill
    {
        $service = app(JobArtV2RoleEffectService::class);
        $sourceActionId = $state->beginSourceAction();
        $service->beginAction($actor, $state, $sourceActionId);
        $execution = clone $art;
        $service->applyForExecution($actor, $target, $state, $art, $execution);
        $service->beginJobArtCast($actor, $state, $art);

        return $execution;
    }

    private function setHuntingMarks(BattleActor $target, BattleActor $owner, int $count): void
    {
        $target->jobArtV2ProgressionState()->huntingMarks['actor:'.spl_object_id($owner)] = $count;
    }

    private function timed(string $key, float $rate, float $strength, bool $removable): JobArtV2TimedEffectState
    {
        return new JobArtV2TimedEffectState(
            key: $key,
            statModifiers: ['str' => $rate],
            appliedRound: 1,
            remainingRounds: 3,
            sourceActionId: 1,
            sourceSkillId: 1,
            removable: $removable,
            strength: $strength,
        );
    }

    private function assertDrainHeal(int $hpBefore, int $damage, int $expectedHp): void
    {
        [$actor, $target, $state] = $this->battle(19, actorOverrides: ['hp' => $hpBefore, 'max_hp' => 1_000]);
        $art = $this->attach($actor, $this->art(19, 9), 'current');
        app(JobArtBattleSupportService::class)->beginAction($actor, $state);
        $execution = app(JobArtBattleSupportService::class)->skillForExecution($actor, $art, $state, $target);
        $spBefore = $actor->mp;
        $method = new ReflectionMethod(BattleService::class, 'applyJobArtStructuredSideEffects');
        $method->invoke(app(BattleService::class), $actor, $target, $state, $execution, $damage, 1.0, true);

        $this->assertSame($expectedHp, $actor->hp);
        $this->assertSame($spBefore, $actor->mp, 'Soul Eater never restores SP.');
    }

    /** @return array{string, string, int, int} */
    private function executionTuple(Skill $skill): array
    {
        return [
            (string) $skill->effect_template,
            (string) $skill->damage_type,
            (int) $skill->power,
            (int) $skill->hit_count,
        ];
    }
}
