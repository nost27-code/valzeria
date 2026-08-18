<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\HitResult;
use App\Services\JobArtV2CrownBalanceCatalog;
use App\Services\JobArtV2RoleEffectService;
use Tests\TestCase;

final class JobArtV2CanonicalSelfBuffRuntimeAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.hit_resolution' => true,
            'battle.job_art_v2.damage_application' => true,
            'battle.job_art_v2.resources' => true,
            'battle.job_art_v2.fields' => true,
        ]);
    }

    public function test_every_canonical_self_buff_matches_runtime_in_all_battle_contexts_and_origins(): void
    {
        $catalog = app(JobArtV2CrownBalanceCatalog::class);
        $service = app(JobArtV2RoleEffectService::class);
        $canonicalArts = array_values(array_filter(
            $this->masterArts(),
            static fn (Skill $skill): bool => $catalog->hasSelfBuff($skill),
        ));

        $this->assertCount(36, $canonicalArts, 'A new canonical self buff must enter this runtime audit.');
        $sharedTemplateArts = [];

        foreach ($canonicalArts as $source) {
            foreach (['pve', 'boss', 'tower', 'pvp', 'champ', 'arena_npc'] as $battleType) {
                foreach (['current', 'inherited'] as $origin) {
                    foreach (['physical', 'magical'] as $normalAttackType) {
                        [$actor, $target, $state] = $this->battle(
                            $source,
                            $battleType,
                            $origin,
                            $normalAttackType,
                        );
                        $label = implode(' / ', [
                            $this->identity($source),
                            $battleType,
                            $origin,
                            $normalAttackType,
                        ]);
                        $expectedModifiers = $catalog->selfBuffModifiers($source, $actor);
                        $rawBefore = $this->rawStats($actor);

                        $sourceActionId = $state->beginSourceAction();
                        $service->beginAction($actor, $state, $sourceActionId);
                        $execution = clone $source;
                        $service->applyForExecution($actor, $target, $state, $source, $execution);
                        $service->beginJobArtCast($actor, $state, $source);

                        $sharedEffect = null;
                        if (in_array(
                            (string) $execution->effect_template,
                            ['SELF_BUFF', 'DAMAGE_BUFF', 'MAGICAL_DAMAGE_BUFF'],
                            true,
                        )) {
                            $sharedTemplateArts[$this->identity($source)] = true;
                            $change = $service->applySharedSelfBuff($actor, $state, $execution);
                            $isMagical = (string) $execution->effect_template === 'MAGICAL_DAMAGE_BUFF'
                                || $actor->usesMagForNormalAttack();
                            $mainStat = $isMagical ? 'mag' : 'str';
                            $subStat = $isMagical ? 'spr' : 'def';
                            $this->assertSame($isMagical ? 'MAG' : 'ATK', $change['main_label'] ?? null, $label);
                            $this->assertSame($rawBefore[$mainStat], $change['main_before'] ?? null, $label);
                            $this->assertSame(
                                (int) floor(($rawBefore[$mainStat] * (1 + ($expectedModifiers[$mainStat] ?? 0.0))) + 1.0e-9),
                                $change['main_after'] ?? null,
                                $label,
                            );
                            $this->assertSame($isMagical ? 'SPR' : 'DEF', $change['sub_label'] ?? null, $label);
                            $this->assertSame($rawBefore[$subStat], $change['sub_before'] ?? null, $label);
                            $this->assertSame(
                                (int) floor(($rawBefore[$subStat] * (1 + ($expectedModifiers[$subStat] ?? 0.0))) + 1.0e-9),
                                $change['sub_after'] ?? null,
                                $label,
                            );
                            $sharedEffect = $actor->jobArtV2TimedEffect(
                                'canonical_self_buff:'.(int) $source->job_id.':'.(int) $source->learn_rank,
                            );
                        }

                        $service->completeJobArtCast($actor, $target, $state, $source, HitResult::HIT);

                        $effect = $actor->jobArtV2TimedEffect(
                            'canonical_self_buff:'.(int) $source->job_id.':'.(int) $source->learn_rank,
                        );
                        $this->assertNotNull($effect, $label);
                        $this->assertSame($expectedModifiers, $effect->statModifiers, $label);
                        $this->assertSame($catalog->durationTurns($source), $effect->remainingRounds, $label);
                        $this->assertTrue($effect->removable, $label);
                        if ($sharedEffect !== null) {
                            $this->assertSame($sharedEffect, $effect, $label.' one application per source action');
                        }
                        $this->assertSame($rawBefore, $this->rawStats($actor), $label.' raw stats');
                        $this->assertEffectiveStats($actor, $rawBefore, $expectedModifiers, $label);

                        $service->endRound($state);
                        $this->assertNotNull(
                            $actor->jobArtV2TimedEffect($effect->key),
                            $label.' application round',
                        );
                        for ($round = 2; $round <= $catalog->durationTurns($source) + 1; $round++) {
                            $state->turnCount = $round;
                            $service->endRound($state);
                        }

                        $this->assertNull($actor->jobArtV2TimedEffect($effect->key), $label.' expiry');
                        $this->assertSame($rawBefore, $this->effectiveStats($actor), $label.' restored');
                    }
                }
            }
        }

        $this->assertCount(24, $sharedTemplateArts, 'Shared-template arts must stay inside the canonical runtime audit.');
    }

    public function test_shared_self_buff_is_wired_to_battle_state_in_every_route(): void
    {
        $battle = (string) file_get_contents(app_path('Services/BattleService.php'));
        $this->assertStringContainsString(
            'applySharedSelfBuff($attacker, $state, $skill',
            $battle,
        );

        $support = (string) file_get_contents(app_path('Services/JobArtBattleSupportService.php'));
        $this->assertMatchesRegularExpression(
            '/applySharedSelfBuff\(\s*BattleActor \$actor,\s*BattleState \$state,\s*Skill \$skill,/s',
            $support,
        );
        $this->assertStringContainsString(
            'applySharedSelfBuff($actor, $state, $skill',
            $support,
        );

        foreach (['PvPBattleService.php', 'ChampBattleService.php', 'ArenaNpcBattleService.php'] as $file) {
            $source = (string) file_get_contents(app_path('Services/'.$file));
            $this->assertMatchesRegularExpression(
                '/applySharedSelfBuff\\(\\s*\\$attacker,\\s*\\$state,\\s*\\$skill(?:,|\\))/s',
                $source,
                $file,
            );
        }
    }

    public function test_travel_preparation_applies_all_four_stats_as_its_description_states(): void
    {
        $skill = new Skill([
            'job_id' => 20,
            'learn_rank' => 1,
            'name' => '旅支度',
            'skill_type' => 'job_art',
        ]);
        $actor = new BattleActor('actor', true, [
            'str' => 100,
            'def' => 100,
            'mag' => 100,
            'spr' => 100,
        ]);

        $this->assertSame(
            ['str' => 0.10, 'def' => 0.10, 'mag' => 0.10, 'spr' => 0.10],
            app(JobArtV2CrownBalanceCatalog::class)->selfBuffModifiers($skill, $actor),
        );
    }

    /** @return list<Skill> */
    private function masterArts(): array
    {
        $rows = json_decode(
            (string) file_get_contents(database_path('data/job_arts.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertCount(282, $rows, 'The runtime audit must inspect the complete Job Art master.');

        $skills = [];
        foreach ($rows as $index => $row) {
            $skill = new Skill($row);
            $power = $this->powerFromHint($row['power_hint'] ?? 100);
            $skill->setAttribute('id', 800_000 + $index);
            $skill->setAttribute('power', $power);
            $skill->setAttribute('power_multiplier', $power / 100);
            $skills[] = $skill;
        }

        return $skills;
    }

    /** @return array{BattleActor, BattleActor, BattleState} */
    private function battle(
        Skill $skill,
        string $battleType,
        string $origin,
        string $normalAttackType,
    ): array {
        $currentJobId = $origin === 'current' ? (int) $skill->job_id : 62;
        $actor = new BattleActor('actor', true, [
            'hp' => 1_000,
            'max_hp' => 1_000,
            'mp' => 500,
            'max_mp' => 500,
            'str' => 100,
            'def' => 100,
            'agi' => 100,
            'mag' => 120,
            'spr' => 100,
            'luk' => 100,
            'current_job_id' => $currentJobId,
        ]);
        $actor->normalAttackType = $normalAttackType;
        $actor->jobArts = [$skill];
        $actor->jobArtOrigins[(int) $skill->id] = $origin;
        $actor->jobArtRates[(int) $skill->id] = 1.0;

        $target = new BattleActor('target', false, [
            'hp' => 1_000,
            'max_hp' => 1_000,
            'mp' => 500,
            'max_mp' => 500,
            'str' => 100,
            'def' => 100,
            'agi' => 100,
            'mag' => 100,
            'spr' => 100,
            'luk' => 100,
            'current_job_id' => 1,
        ]);

        return [$actor, $target, new BattleState($actor, $target, $battleType)];
    }

    /** @return array{str:int,def:int,mag:int,spr:int} */
    private function rawStats(BattleActor $actor): array
    {
        return [
            'str' => $actor->str,
            'def' => $actor->def,
            'mag' => $actor->mag,
            'spr' => $actor->spr,
        ];
    }

    /** @return array{str:int,def:int,mag:int,spr:int} */
    private function effectiveStats(BattleActor $actor): array
    {
        return [
            'str' => $actor->effectiveStr(),
            'def' => $actor->effectiveDef(),
            'mag' => $actor->effectiveMag(),
            'spr' => $actor->effectiveSpr(),
        ];
    }

    /**
     * @param array{str:int,def:int,mag:int,spr:int} $raw
     * @param array<string, float> $modifiers
     */
    private function assertEffectiveStats(
        BattleActor $actor,
        array $raw,
        array $modifiers,
        string $label,
    ): void {
        $expected = [];
        foreach ($raw as $stat => $baseValue) {
            $modifier = (float) ($modifiers[$stat] ?? 0.0);
            $expected[$stat] = (int) floor(($baseValue * (1 + $modifier)) + 1.0e-9);
        }

        $this->assertSame($expected, $this->effectiveStats($actor), $label.' effective stats');
    }

    private function identity(Skill $skill): string
    {
        return implode(':', [(int) $skill->job_id, (int) $skill->learn_rank, (string) $skill->name]);
    }

    private function powerFromHint(mixed $hint): int
    {
        if (is_numeric($hint)) {
            return (int) $hint;
        }

        preg_match('/\d+/', (string) $hint, $matches);

        return isset($matches[0]) ? (int) $matches[0] : 100;
    }
}
