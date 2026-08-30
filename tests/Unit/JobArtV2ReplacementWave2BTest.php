<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\ActionResolver;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageCalculator;
use App\Services\Battle\HitResult;
use App\Services\Battle\JobArtHitPower;
use App\Services\JobArtV2ActiveEvasionProvider;
use App\Services\JobArtBattleSupportService;
use App\Services\JobArtV2CDesignCatalog;
use App\Services\JobArtV2CDesignClassificationCatalog;
use App\Services\JobArtV2CardDescriptionCatalog;
use App\Services\JobArtV2CrownBalanceCatalog;
use App\Services\JobArtV2FeatureGate;
use App\Services\JobArtV2HitRandomSource;
use App\Services\JobArtV2PenetrationService;
use App\Services\JobArtV2PrototypeCatalog;
use App\Services\JobArtV2RoleEffectCatalog;
use App\Services\JobArtV2RoleEffectService;
use App\Support\JobArtEffectCatalog;
use ReflectionClass;
use Tests\TestCase;

final class JobArtV2ReplacementWave2BTest extends TestCase
{
    /** @var array<string, array{job_id:int,rank:int,name:string,resource:string,cost:int,power:int,hits:int,sp:int}> */
    private const ARTS = [
        'pierce' => ['job_id' => 2, 'rank' => 9, 'name' => '穿貫', 'resource' => 'dragon_force', 'cost' => 12, 'power' => 225, 'hits' => 1, 'sp' => 42],
        'hunt_start' => ['job_id' => 3, 'rank' => 1, 'name' => '影狩りの構え', 'resource' => 'hunt', 'cost' => 0, 'power' => 90, 'hits' => 1, 'sp' => 8],
        'hunt_combo' => ['job_id' => 3, 'rank' => 5, 'name' => '急所狙い', 'resource' => 'hunt', 'cost' => 4, 'power' => 145, 'hits' => 1, 'sp' => 16],
        'aim_start' => ['job_id' => 4, 'rank' => 1, 'name' => '精密射撃', 'resource' => 'aim', 'cost' => 0, 'power' => 90, 'hits' => 1, 'sp' => 8],
        'break_start' => ['job_id' => 5, 'rank' => 1, 'name' => '崩し打ち', 'resource' => 'break', 'cost' => 0, 'power' => 90, 'hits' => 1, 'sp' => 6],
        'break_combo' => ['job_id' => 5, 'rank' => 5, 'name' => '連環崩打', 'resource' => 'break', 'cost' => 4, 'power' => 145, 'hits' => 3, 'sp' => 20],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.hit_resolution' => true,
            'battle.job_art_v2.damage_application' => true,
            'battle.job_art_v2.resources' => true,
            'battle.job_art_v2.penetration' => true,
            'battle.job_art_v2.fields' => true,
            'battle.job_art_v2.normalized_sp' => false,
            'battle.job_art_v2.c_design_prototype' => false,
            'battle.job_art_v2.ultimate_counterplay' => false,
        ]);
    }

    public function test_master_descriptions_and_metadata_define_only_the_six_approved_replacements(): void
    {
        $rows = $this->masterRows();
        $this->assertCount(282, $rows);

        $expectedRates = ['2:9' => 8, '3:1' => 24, '3:5' => 16, '4:1' => 24, '5:1' => 24, '5:5' => 16];
        foreach (self::ARTS as $case) {
            $row = $this->masterRow($case['job_id'], $case['rank']);
            $key = $case['job_id'].':'.$case['rank'];
            $this->assertSame($case['name'], $row['name']);
            $this->assertIsInt($row['power_hint'], $key.' power_hint must be an explicit damage power.');
            $expectedMasterPower = $case['rank'] === 5 ? 100 : $case['power'];
            $this->assertSame($expectedMasterPower, $this->powerFromHint($row['power_hint'] ?? null));
            $this->assertSame($case['sp'], $row['sp_cost_fixed'] ?? null, $key.' fixed SP must be explicit in the master.');
            $this->assertSame($expectedRates[$key], (int) $row['activation_rate'], $key.' activation rate');
        }

        $descriptions = app(JobArtV2CardDescriptionCatalog::class)->all();
        $this->assertSame('竜気を-12し、相手に威力225%の物理ダメージを与える。この攻撃は相手の防御を50%無視する。', $descriptions['2:9:穿貫'] ?? null);
        $this->assertSame('狩猟印を+4し、相手に威力90%の物理ダメージを与える。その後、3ターンの間、相手の敏捷を-15%する。', $descriptions['3:1:影狩りの構え'] ?? null);
        $this->assertSame('狩猟印を-4し、相手に威力145%の物理ダメージを与える。この攻撃の会心率を+15ポイントする。', $descriptions['3:5:急所狙い'] ?? null);
        $this->assertSame('照準を+4し、相手に威力90%の物理ダメージを与える。この攻撃の命中率を+15ポイント、会心率を+10ポイントする。', $descriptions['4:1:精密射撃'] ?? null);
        $this->assertSame('崩しを+4し、相手に威力90%の物理ダメージを与える。その後、3ターンの間、相手の防御を-15%する。', $descriptions['5:1:崩し打ち'] ?? null);
        $this->assertSame('崩しを-4し、相手に合計威力145%の物理ダメージを3回に分けて与える。その後、3ターンの間、相手の防御と精神を-15%する。', $descriptions['5:5:連環崩打'] ?? null);
        foreach (['2:9:巨人断ち', '3:1:すり抜け', '3:5:不意打ち', '4:1:足止め矢', '5:1:気合拳', '5:5:連打'] as $oldIdentity) {
            $this->assertArrayNotHasKey($oldIdentity, $descriptions);
        }

        $role = app(JobArtV2RoleEffectCatalog::class);
        $pierce = $role->forArt($this->art(2, 9));
        $this->assertSame(50, $this->masterRow(2, 9)['def_ignore_percent'] ?? null);
        $this->assertSame(50, $pierce['damage_stat_route']['defense_ignore_percent'] ?? null);
        $precision = $role->forArt($this->art(4, 1));
        $this->assertSame(15, $precision['accuracy_delta_points'] ?? null);
        $this->assertTrue($precision['preserve_legacy_sure_hit'] ?? false);
        $this->assertSame(10, $precision['critical_delta_points'] ?? null);
        $this->assertSame('existing_roll_delta', $precision['critical_mode'] ?? null);
        $vital = $role->forArt($this->art(3, 5));
        $this->assertSame(15, $vital['critical_delta_points'] ?? null);
        $this->assertSame('existing_roll_delta', $vital['critical_mode'] ?? null);

        $prototype = app(JobArtV2PrototypeCatalog::class);
        $this->assertSame(0.50, $prototype->artPenetrationMetadata($this->art(2, 9))['penetration_rate'] ?? null);
        $this->assertSame(0.25, $prototype->artPenetrationMetadata($this->art(2, 5))['penetration_rate'] ?? null, '二段穿ちは25%のまま。');

        $crown = (new ReflectionClass(JobArtV2CrownBalanceCatalog::class))->getConstant('ARTS');
        $this->assertCount(95, $crown);
        $this->assertSame(['debuffs' => ['agi' => 15], 'duration' => 3], $crown['3:1:影狩りの構え'] ?? null);
        $this->assertSame(['debuffs' => ['def' => 15], 'duration' => 3], $crown['5:1:崩し打ち'] ?? null);
        $this->assertSame(['hit_count' => 3, 'debuffs' => ['def' => 15, 'spr' => 15], 'duration' => 3], $crown['5:5:連環崩打'] ?? null);

        $portable = (new ReflectionClass(JobArtV2CDesignCatalog::class))->getConstant('PORTABLE_RANK_ONE');
        $this->assertArrayHasKey('3:1:影狩りの構え', $portable);
        $this->assertArrayHasKey('4:1:精密射撃', $portable);
        $this->assertArrayHasKey('5:1:崩し打ち', $portable);
        $classification = (new ReflectionClass(JobArtV2CDesignClassificationCatalog::class))->getConstant('B2_ARTS');
        foreach (['3:1:影狩りの構え', '3:5:急所狙い', '4:1:精密射撃', '5:1:崩し打ち', '5:5:連環崩打'] as $identity) {
            $this->assertArrayHasKey($identity, $classification);
        }
    }

    public function test_all_six_arts_keep_the_same_execution_semantics_in_six_contexts_and_both_origins(): void
    {
        $support = app(JobArtBattleSupportService::class);
        foreach (self::ARTS as $key => $case) {
            foreach (['pve', 'boss', 'tower', 'pvp', 'champ', 'arena_npc'] as $battleType) {
                foreach (['current', 'inherited'] as $origin) {
                    [$actor, $target, $state] = $this->battle($origin === 'current' ? $case['job_id'] : 62, $battleType);
                    $art = $this->attach($actor, $this->art($case['job_id'], $case['rank']), $origin);
                    $actor->configureResource($case['resource'], 12);
                    $actor->setResource($case['resource'], $case['cost']);

                    $label = implode(' / ', [$case['name'], $battleType, $origin]);
                    $support->beginAction($actor, $state);
                    $this->assertTrue($support->consumeAndMarkUse($actor, $state, $art), $label);
                    $execution = $support->skillForExecution($actor, $art, $state, $target);

                    $this->assertSame('PHYSICAL_DAMAGE', (string) $execution->effect_template, $label);
                    $this->assertSame('physical', (string) $execution->damage_type, $label);
                    $this->assertSame($case['power'], (int) $execution->power, $label);
                    $this->assertSame($case['hits'], (int) $execution->hit_count, $label);
                    $this->assertSame(0, (int) $execution->self_buff_percent, $label);

                    if ($key === 'pierce') {
                        $this->assertSame(50, (int) $execution->getAttribute('job_art_v2_defense_ignore_percent'), $label);
                        $penetrationOverrides = $support->defenseOverrides($actor, $target, $state, $execution);
                        $statOverrides = $support->damageStatOverrides($actor, $target, $execution);
                        $resolvedDef = $statOverrides['def'] ?? $penetrationOverrides['def'];
                        $this->assertSame(50, $penetrationOverrides['def'], $label.' penetration fallback');
                        $this->assertSame(50, $statOverrides['def'], $label.' role route');
                        $this->assertSame(0.50, $statOverrides['applied_ignore_rate'], $label.' applied ignore rate');
                        $this->assertSame(50, $resolvedDef, $label.' runtime composition must resolve once, not cumulatively');
                    }
                    if ($key === 'hunt_start') {
                        $this->assertSame(15, (int) $execution->enemy_spd_down_percent, $label);
                    }
                    if ($key === 'break_start') {
                        $this->assertSame(15, (int) $execution->enemy_def_down_percent, $label);
                    }
                    if ($key === 'break_combo') {
                        $this->assertSame(15, (int) $execution->enemy_def_down_percent, $label);
                        $this->assertSame(15, (int) $execution->enemy_spr_down_percent, $label);
                    }

                    $support->completeJobArtCast($actor, $state, $art, HitResult::HIT, $target);
                    $this->assertSame(
                        $case['rank'] === 1 ? 4 : 0,
                        $actor->getResource($case['resource']),
                        $label.' resource delta',
                    );
                }
            }
        }
    }

    public function test_precision_shot_uses_the_aim_cap_and_vital_hit_in_player_competition_but_keeps_npc_rank_sure_hit(): void
    {
        foreach (['pvp' => 12.0, 'champ' => 15.0] as $battleType => $vitalChance) {
            foreach (['current', 'inherited'] as $origin) {
                [$actor, $target] = $this->battle($origin === 'current' ? 4 : 62, $battleType);
                $precision = $this->attach($actor, $this->art(4, 1), $origin);
                $random = $this->hitRandom([99, (int) $vitalChance]);
                $label = $battleType.':'.$origin;
                $resolution = $this->actionResolver($random)->resolveJobArtWithDetails(
                    $actor,
                    $target,
                    $precision,
                    $battleType,
                );

                $this->assertSame(HitResult::HIT, $resolution?->hitResult, $label);
                $this->assertSame(105.0, $resolution?->rawHitChance, $label);
                $this->assertSame(99.0, $resolution?->effectiveHitChance, $label);
                $this->assertSame(5.0, $resolution?->accuracyOverflow, $label);
                $this->assertSame($vitalChance, $resolution?->vitalHitChance, $label);
                $this->assertTrue($resolution?->vitalHit ?? false, $label);
                $this->assertSame(2, $random->calls, $label);

                $missRandom = $this->hitRandom([100]);
                $this->assertSame(
                    HitResult::MISS,
                    $this->actionResolver($missRandom)->resolveJobArt($actor, $target, $precision, $battleType),
                    $label.' respects the 99% Aim cap.',
                );
                $this->assertSame(1, $missRandom->calls, $label.':miss');
            }
        }

        foreach (['current', 'inherited'] as $origin) {
            [$actor, $target] = $this->battle($origin === 'current' ? 4 : 62, 'arena_npc');
            $precision = $this->attach($actor, $this->art(4, 1), $origin);
            $random = $this->hitRandom([100]);
            $resolution = $this->actionResolver($random)->resolveJobArtWithDetails(
                $actor,
                $target,
                $precision,
                'arena_npc',
            );

            $this->assertSame(HitResult::HIT, $resolution?->hitResult, $origin);
            $this->assertSame(100.0, $resolution?->effectiveHitChance, $origin);
            $this->assertSame(0.0, $resolution?->vitalHitChance, $origin);
            $this->assertSame(1, $random->calls, $origin);
        }

        foreach (['pve', 'boss', 'tower'] as $battleType) {
            foreach (['current', 'inherited'] as $origin) {
                foreach ([[98, HitResult::HIT], [99, HitResult::MISS]] as [$roll, $expected]) {
                    [$actor, $target] = $this->battle($origin === 'current' ? 4 : 62, $battleType);
                    $precision = $this->attach($actor, $this->art(4, 1), $origin);

                    $this->assertSame(
                        $expected,
                        $this->actionResolver($this->hitRandom([$roll]))->resolveJobArt($actor, $target, $precision, $battleType),
                        $battleType.':'.$origin.':'.$roll,
                    );
                }
            }
        }
    }

    public function test_competitive_accuracy_bonus_without_legacy_preserve_flag_still_performs_a_capped_hit_roll(): void
    {
        foreach (['pvp', 'champ', 'arena_npc'] as $battleType) {
            foreach (['current', 'inherited'] as $origin) {
                [$actor, $target] = $this->battle($origin === 'current' ? 18 : 62, $battleType);
                $criticalShot = $this->attach($actor, $this->art(18, 5), $origin);
                $random = $this->hitRandom([100]);
                $label = $battleType.':'.$origin;

                $this->assertSame(
                    HitResult::MISS,
                    $this->actionResolver($random)->resolveJobArt($actor, $target, $criticalShot, $battleType),
                    $label.' must not use the legacy base-hit shortcut without the preserve flag.',
                );
                $this->assertSame(1, $random->calls, $label);
            }
        }
    }

    public function test_pierce_defense_sources_resolve_once_and_cap_inputs_above_fifty_percent(): void
    {
        [$actor, $target, $state] = $this->battle(2);
        $support = app(JobArtBattleSupportService::class);
        $pierce = $this->attach($actor, $this->art(2, 9), 'current');
        $actor->configureResource('dragon_force', 12);
        $actor->setResource('dragon_force', 12);
        $support->beginAction($actor, $state);
        $this->assertTrue($support->consumeAndMarkUse($actor, $state, $pierce));
        $execution = $support->skillForExecution($actor, $pierce, $state, $target);

        $this->assertSame(0.50, JobArtV2PenetrationService::MAX_PENETRATION_RATE);
        $this->assertSame(50, $support->defenseOverrides($actor, $target, $state, $execution)['def']);
        $this->assertSame(50, $support->damageStatOverrides($actor, $target, $execution)['def']);

        $execution->setAttribute('def_ignore_percent', 60);
        $execution->setAttribute('job_art_v2_defense_ignore_percent', 60);
        $penetrationOverrides = $support->defenseOverrides($actor, $target, $state, $execution);
        $statOverrides = $support->damageStatOverrides($actor, $target, $execution);

        $this->assertSame(50, $penetrationOverrides['def']);
        $this->assertSame(50, $statOverrides['def']);
        $this->assertSame(0.50, $statOverrides['applied_ignore_rate']);
        $this->assertSame(50, $statOverrides['def'] ?? $penetrationOverrides['def']);
    }

    public function test_all_runtime_damage_composition_points_prefer_role_override_over_penetration_fallback(): void
    {
        $battle = file_get_contents(base_path('app/Services/BattleService.php'));
        $this->assertStringContainsString("\$statOverrides['def'] ?? \$overrideDef", $battle);

        foreach ([
            'pvp' => 'app/Services/PvPBattleService.php',
            'champ' => 'app/Services/ChampBattleService.php',
            'arena_npc' => 'app/Services/ArenaNpcBattleService.php',
        ] as $battleType => $path) {
            $this->assertStringContainsString(
                "\$statOverrides['def'] ?? \$overrides['def']",
                file_get_contents(base_path($path)),
                $battleType,
            );
        }
    }

    public function test_break_debuffs_stack_by_natural_key_and_three_hit_power_is_split_without_loss(): void
    {
        [$actor, $target, $state] = $this->battle(5);
        $service = app(JobArtV2RoleEffectService::class);

        $start = $this->attach($actor, $this->art(5, 1), 'current');
        $startExecution = $this->beginRoleCast($actor, $target, $state, $start);
        $startResult = $service->applyTimedStructuredDebuffs($actor, $target, $state, $startExecution);
        $this->assertSame(3, $startResult['duration_turns'] ?? null);
        $this->assertSame(85, $target->effectiveDef());
        $this->assertSame(100, $target->effectiveSpr());

        $combo = $this->attach($actor, $this->art(5, 5), 'current');
        $comboExecution = $this->beginRoleCast($actor, $target, $state, $combo);
        $comboResult = $service->applyTimedStructuredDebuffs($actor, $target, $state, $comboExecution);
        $this->assertSame(3, $comboResult['duration_turns'] ?? null);
        $this->assertSame(70, $target->effectiveDef());
        $this->assertSame(85, $target->effectiveSpr());
        $this->assertCount(2, $target->jobArtV2TimedEffects());

        $hitPowers = JobArtHitPower::split((int) $comboExecution->power, (int) $comboExecution->hit_count);
        $this->assertSame([49, 48, 48], $hitPowers);
        $this->assertSame(145, array_sum($hitPowers));
    }

    public function test_pve_job_art_critical_bonus_metadata_and_existing_global_critical_caps_are_unchanged(): void
    {
        [$actor, $target] = $this->battle(4);
        $precision = $this->attach($actor, $this->art(4, 1), 'current');
        $vital = $this->art(3, 5);
        $role = app(JobArtV2RoleEffectService::class);

        // PvE keeps the existing global critical roll; competitive Aim arts use the separate Vital Hit roll.
        $this->assertSame(10.0, $role->criticalBonusPoints($actor, $precision));
        $actor->jobArts = [$vital];
        $actor->jobArtOrigins[(int) $vital->id] = 'inherited';
        $this->assertSame(15.0, $role->criticalBonusPoints($actor, $vital));

        $actor->luk = 10_000;
        $target->luk = 0;
        $calculator = new DamageCalculator;
        $this->assertSame(30.0, $calculator->criticalChance($actor, $target, 10));
        // These pin shared legacy critical caps; Vital Hit intentionally does not use Luck or these caps.
        mt_srand($this->seedForFirstRoll(20));
        $this->assertTrue($calculator->isDuelCritical($actor, $target, 15));
        mt_srand($this->seedForFirstRoll(21));
        $this->assertFalse($calculator->isDuelCritical($actor, $target, 15));
        mt_srand($this->seedForFirstRoll(12));
        $this->assertTrue($calculator->isRankBattleCritical($actor, $target, 15));
        mt_srand($this->seedForFirstRoll(13));
        $this->assertFalse($calculator->isRankBattleCritical($actor, $target, 15));

        $reflection = new ReflectionClass(DamageCalculator::class);
        $this->assertSame(1.50, $reflection->getConstant('DUEL_CRITICAL_MULTIPLIER'));
        $this->assertSame(1.50, $reflection->getConstant('RANK_BATTLE_CRITICAL_MULTIPLIER'));
    }

    public function test_existing_wave_two_a_and_common_primitives_are_unchanged(): void
    {
        $this->assertSame('狩猟の完成', $this->masterRow(17, 9)['name']);
        $this->assertSame('崩落', $this->masterRow(33, 9)['name']);
        $this->assertSame('天測の陣', $this->masterRow(6, 5)['name']);
        $this->assertSame('魂喰らい', $this->masterRow(19, 9)['name']);

        $existingCritical = app(JobArtV2RoleEffectCatalog::class)->forArt($this->syntheticArt(18, 5, 'クリティカルショット'));
        $this->assertSame(6, $existingCritical['accuracy_delta_points'] ?? null);
        $this->assertSame(10, $existingCritical['critical_delta_points'] ?? null);
        $this->assertSame('existing_roll_delta', $existingCritical['critical_mode'] ?? null);
    }

    /** @return list<array<string, mixed>> */
    private function masterRows(): array
    {
        return json_decode((string) file_get_contents(database_path('data/job_arts.json')), true, 512, JSON_THROW_ON_ERROR);
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
        $power = $this->powerFromHint($row['power_hint'] ?? 100);
        $template = (string) $row['effect_template'];
        $skill = new Skill(array_replace($row, [
            'power' => $power,
            'power_multiplier' => $power / 100,
            'damage_type' => JobArtEffectCatalog::damageType($template),
            'hit_count' => (int) ($row['hit_count'] ?? JobArtEffectCatalog::hitCount($template)),
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

    /** @return array{BattleActor, BattleActor, BattleState} */
    private function battle(int $currentJob, string $battleType = 'pve'): array
    {
        $actor = new BattleActor('actor', true, [
            'hp' => 1_000, 'max_hp' => 1_000, 'mp' => 1_000, 'max_mp' => 1_000,
            'str' => 100, 'def' => 100, 'agi' => 100, 'mag' => 100, 'spr' => 100, 'luk' => 100,
            'current_job_id' => $currentJob,
        ]);
        $target = new BattleActor('target', false, [
            'hp' => 1_000, 'max_hp' => 1_000, 'mp' => 1_000, 'max_mp' => 1_000,
            'str' => 100, 'def' => 100, 'agi' => 100, 'mag' => 100, 'spr' => 100, 'luk' => 100,
            'current_job_id' => 60,
        ]);

        return [$actor, $target, new BattleState($actor, $target, $battleType)];
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

    private function actionResolver(JobArtV2HitRandomSource $random): ActionResolver
    {
        return new ActionResolver(
            app(JobArtV2FeatureGate::class),
            new DamageCalculator,
            $random,
            new JobArtV2ActiveEvasionProvider,
        );
    }

    private function hitRandom(array $rolls): JobArtV2HitRandomSource
    {
        return new class($rolls) extends JobArtV2HitRandomSource
        {
            public int $calls = 0;

            public function __construct(private array $rolls) {}

            public function percentRoll(): int
            {
                return $this->rolls[$this->calls++] ?? 100;
            }
        };
    }

    private function powerFromHint(mixed $hint): int
    {
        if (is_numeric($hint)) {
            return (int) $hint;
        }
        preg_match('/\d+/', (string) $hint, $matches);

        return isset($matches[0]) ? (int) $matches[0] : 100;
    }

    private function seedForFirstRoll(int $expectedRoll): int
    {
        for ($seed = 1; $seed <= 10_000; $seed++) {
            mt_srand($seed);
            if (rand(1, 100) === $expectedRoll) {
                return $seed;
            }
        }

        $this->fail("Could not find a deterministic seed for roll {$expectedRoll}.");
    }
}
