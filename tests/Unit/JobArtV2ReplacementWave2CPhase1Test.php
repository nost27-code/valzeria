<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\HitResult;
use App\Services\JobArtBattleSupportService;
use App\Services\JobArtV2CardDescriptionCatalog;
use App\Services\JobArtV2CDesignClassificationCatalog;
use App\Services\JobArtV2CrownBalanceCatalog;
use App\Services\JobArtV2RoleEffectCatalog;
use App\Support\JobArtEffectCatalog;
use ReflectionClass;
use Tests\TestCase;

final class JobArtV2ReplacementWave2CPhase1Test extends TestCase
{
    /** @var array<string, array{job_id:int,rank:int,name:string,resource:string,cost:int,power:int,hits:int,sp:int}> */
    private const ARTS = [
        'counter_combo' => ['job_id' => 1, 'rank' => 5, 'name' => '受け返し', 'resource' => 'sword_momentum', 'cost' => 4, 'power' => 145, 'hits' => 1, 'sp' => 20],
        'hunt_start' => ['job_id' => 17, 'rank' => 1, 'name' => '影伏せ', 'resource' => 'hunt', 'cost' => 0, 'power' => 100, 'hits' => 1, 'sp' => 8],
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

    public function test_master_descriptions_and_exact_catalogs_define_only_the_two_approved_replacements(): void
    {
        $this->assertCount(282, $this->masterRows());

        foreach (self::ARTS as $case) {
            $row = $this->masterRow($case['job_id'], $case['rank']);
            $this->assertSame($case['name'], $row['name']);
            $this->assertSame('PHYSICAL_DAMAGE', $row['effect_template']);
            $this->assertSame('attack', $row['art_category']);
            $this->assertSame(
                $case['job_id'] === 1 && $case['rank'] === 5 ? 100 : $case['power'],
                $row['power_hint'],
            );
            $this->assertSame($case['sp'], $row['sp_cost_fixed'] ?? null);
            $this->assertSame(1, $row['hit_count'] ?? 1);
        }

        $descriptions = app(JobArtV2CardDescriptionCatalog::class)->all();
        $this->assertSame(
            '剣勢を-4し、相手に威力145%の物理ダメージを与える。直前の自分の行動後に受け流しへ成功していた場合、最終ダメージを1.35倍にする。',
            $descriptions['1:5:受け返し'] ?? null,
        );
        $this->assertSame(
            '狩猟印を+4し、相手に威力100%の物理ダメージを与える。その後、次に使う封狩系譜の連携または奥義の最終ダメージを1.20倍にする（1回・最大4回の自分の行動機会）。',
            $descriptions['17:1:影伏せ'] ?? null,
        );
        $this->assertArrayNotHasKey('1:5:連斬', $descriptions);
        $this->assertArrayNotHasKey('17:1:煙玉', $descriptions);

        $roles = app(JobArtV2RoleEffectCatalog::class);
        $riposte = $roles->forArt($this->art(1, 5));
        $this->assertSame('parry_success_since_previous_own_action', $riposte['conditional_damage_multiplier']['condition'] ?? null);
        $this->assertSame(1.35, $riposte['conditional_damage_multiplier']['multiplier'] ?? null);
        $shadow = $roles->forArt($this->art(17, 1));
        $this->assertSame(1, $shadow['prepared_effect']['charges'] ?? null);
        $this->assertSame(4, $shadow['prepared_effect']['action_opportunities'] ?? null);
        $this->assertSame(1.20, $shadow['prepared_effect']['damage_multiplier'] ?? null);
        $this->assertSame('hunt', $shadow['prepared_effect']['trigger']['lineage_key'] ?? null);
        $this->assertSame([5, 9], $shadow['prepared_effect']['trigger']['learn_ranks'] ?? null);

        $crown = (new ReflectionClass(JobArtV2CrownBalanceCatalog::class))->getConstant('ARTS');
        $this->assertCount(95, $crown);
        $this->assertSame([], $crown['1:5:受け返し'] ?? null);
        $this->assertSame([], $crown['17:1:影伏せ'] ?? null);
        $this->assertArrayNotHasKey('1:5:連斬', $crown);
        $this->assertArrayNotHasKey('17:1:煙玉', $crown);

        $classification = (new ReflectionClass(JobArtV2CDesignClassificationCatalog::class))->getConstant('B2_ARTS');
        $this->assertArrayHasKey('1:5:受け返し', $classification);
        $this->assertArrayHasKey('17:1:影伏せ', $classification);
        $this->assertArrayNotHasKey('1:5:連斬', $classification);
        $this->assertArrayNotHasKey('17:1:煙玉', $classification);
    }

    public function test_both_replacements_keep_execution_and_resource_semantics_in_six_contexts_and_both_origins(): void
    {
        $support = app(JobArtBattleSupportService::class);

        foreach (self::ARTS as $case) {
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
                    $this->assertSame(0, (int) $execution->enemy_spd_down_percent, $label);

                    $support->completeJobArtCast($actor, $state, $art, HitResult::HIT, $target);
                    $this->assertSame($case['rank'] === 1 ? 4 : 0, $actor->getResource($case['resource']), $label);
                    if ($case['rank'] === 1) {
                        $prepared = $actor->jobArtV2PreparedEffect('hunt_shadow_ambush');
                        $this->assertNotNull($prepared, $label);
                        $this->assertSame(1, $prepared->charges, $label);
                        $this->assertSame(4, $prepared->remainingActionOpportunities, $label);
                    }
                }
            }
        }
    }

    public function test_riposte_uses_only_the_parry_snapshot_from_the_previous_own_action_window(): void
    {
        $support = app(JobArtBattleSupportService::class);

        foreach (['pve', 'boss', 'tower', 'pvp', 'champ', 'arena_npc'] as $battleType) {
            foreach (['current', 'inherited'] as $origin) {
                [$actor, $target, $state] = $this->battle($origin === 'current' ? 1 : 62, $battleType);
                $riposte = $this->attach($actor, $this->art(1, 5), $origin);
                $actor->configureResource('sword_momentum', 12);
                $actor->setResource('sword_momentum', 4);
                $actor->markParrySucceededSinceOwnAction();

                $support->beginAction($actor, $state);
                $this->assertTrue($support->consumeAndMarkUse($actor, $state, $riposte));
                $this->assertSame(1_350, $support->modifyJobArtDamage($actor, $state, $riposte, 1_000), "{$battleType}:{$origin}");
            }
        }

        [$actor, $target, $state] = $this->battle(1);
        $riposte = $this->attach($actor, $this->art(1, 5), 'current');
        $actor->configureResource('sword_momentum', 12);
        $actor->markParrySucceededSinceOwnAction();
        $support->beginAction($actor, $state);
        $support->markNormalAttackAction($actor, $state);
        $actor->setResource('sword_momentum', 4);

        $support->beginAction($actor, $state);
        $this->assertTrue($support->consumeAndMarkUse($actor, $state, $riposte));
        $this->assertSame(1_000, $support->modifyJobArtDamage($actor, $state, $riposte, 1_000));
    }

    public function test_shadow_ambush_buffs_one_hunt_combo_or_finisher_within_four_own_action_opportunities(): void
    {
        $support = app(JobArtBattleSupportService::class);

        foreach (['pve', 'boss', 'tower', 'pvp', 'champ', 'arena_npc'] as $battleType) {
            foreach (['current', 'inherited'] as $origin) {
                [$actor, $target, $state] = $this->battle($origin === 'current' ? 17 : 62, $battleType);
                $producer = $this->art(17, 1);
                $consumer = $this->art(17, 5);
                $this->attachMany($actor, [$producer, $consumer], $origin);
                $actor->configureResource('hunt', 12);

                $support->beginAction($actor, $state);
                $this->assertTrue($support->consumeAndMarkUse($actor, $state, $producer));
                $support->completeJobArtCast($actor, $state, $producer, HitResult::HIT, $target);

                $support->beginAction($actor, $state);
                $this->assertTrue($support->consumeAndMarkUse($actor, $state, $consumer));
                $this->assertSame(1_200, $support->modifyJobArtDamage($actor, $state, $consumer, 1_000), "{$battleType}:{$origin}");
                $this->assertNull($actor->jobArtV2PreparedEffect('hunt_shadow_ambush'));
            }
        }

        [$actor, $target, $state] = $this->battle(17);
        $producer = $this->art(17, 1);
        $consumer = $this->art(17, 5);
        $this->attachMany($actor, [$producer, $consumer], 'current');
        $actor->configureResource('hunt', 12);
        $support->beginAction($actor, $state);
        $support->consumeAndMarkUse($actor, $state, $producer);
        $support->completeJobArtCast($actor, $state, $producer, HitResult::HIT, $target);

        for ($opportunity = 1; $opportunity <= 3; $opportunity++) {
            $support->beginAction($actor, $state);
            $support->markNormalAttackAction($actor, $state);
        }
        $this->assertSame(1, $actor->jobArtV2PreparedEffect('hunt_shadow_ambush')?->remainingActionOpportunities);
        $support->beginAction($actor, $state);
        $this->assertTrue($support->consumeAndMarkUse($actor, $state, $consumer));
        $this->assertSame(1_200, $support->modifyJobArtDamage($actor, $state, $consumer, 1_000));

        [$expiredActor, $expiredTarget, $expiredState] = $this->battle(17);
        $expiredProducer = $this->art(17, 1);
        $expiredConsumer = $this->art(17, 5);
        $this->attachMany($expiredActor, [$expiredProducer, $expiredConsumer], 'current');
        $expiredActor->configureResource('hunt', 12);
        $support->beginAction($expiredActor, $expiredState);
        $support->consumeAndMarkUse($expiredActor, $expiredState, $expiredProducer);
        $support->completeJobArtCast($expiredActor, $expiredState, $expiredProducer, HitResult::HIT, $expiredTarget);
        for ($opportunity = 1; $opportunity <= 4; $opportunity++) {
            $support->beginAction($expiredActor, $expiredState);
            $support->markNormalAttackAction($expiredActor, $expiredState);
        }
        $this->assertNull($expiredActor->jobArtV2PreparedEffect('hunt_shadow_ambush'));
        $support->beginAction($expiredActor, $expiredState);
        $this->assertTrue($support->consumeAndMarkUse($expiredActor, $expiredState, $expiredConsumer));
        $this->assertSame(1_000, $support->modifyJobArtDamage($expiredActor, $expiredState, $expiredConsumer, 1_000));
    }

    /** @return list<array<string, mixed>> */
    private function masterRows(): array
    {
        return json_decode((string) file_get_contents(database_path('data/job_arts.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function masterRow(int $jobId, int $rank): array
    {
        $matches = array_values(array_filter($this->masterRows(), static fn (array $row): bool => (int) $row['job_id'] === $jobId && (int) $row['learn_rank'] === $rank));
        $this->assertCount(1, $matches, "Master identity {$jobId}:{$rank}");

        return $matches[0];
    }

    private function art(int $jobId, int $rank): Skill
    {
        $row = $this->masterRow($jobId, $rank);
        $template = (string) $row['effect_template'];
        $power = is_numeric($row['power_hint'] ?? null) ? (int) $row['power_hint'] : 100;
        $skill = new Skill(array_replace($row, [
            'power' => $power,
            'power_multiplier' => $power / 100,
            'damage_type' => JobArtEffectCatalog::damageType($template),
            'hit_count' => (int) ($row['hit_count'] ?? JobArtEffectCatalog::hitCount($template)),
        ]));
        $skill->setAttribute('id', ($jobId * 100) + $rank);

        return $skill;
    }

    private function attach(BattleActor $actor, Skill $skill, string $origin): Skill
    {
        $this->attachMany($actor, [$skill], $origin);

        return $skill;
    }

    /** @param list<Skill> $skills */
    private function attachMany(BattleActor $actor, array $skills, string $origin): void
    {
        $actor->jobArts = $skills;
        foreach ($skills as $skill) {
            $actor->jobArtOrigins[(int) $skill->id] = $origin;
            $actor->jobArtRates[(int) $skill->id] = 1.0;
        }
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
}
