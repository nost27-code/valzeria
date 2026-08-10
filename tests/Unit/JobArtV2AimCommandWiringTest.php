<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\HitResult;
use App\Services\JobArtV2BattleHudService;
use App\Services\JobArtV2LoadoutPresenter;
use App\Services\JobArtV2ResourceService;
use App\Services\JobArtV2SpPressureService;
use Tests\TestCase;

class JobArtV2AimCommandWiringTest extends TestCase
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
        ]);
    }

    public function test_loadout_presenter_shows_only_frozen_aim_and_command_effects(): void
    {
        $presenter = app(JobArtV2LoadoutPresenter::class);
        $aimOne = $presenter->forArt(65, $this->art(65, 1));
        $aimFive = $presenter->forArt(65, $this->art(65, 5));
        $aimNine = $presenter->forArt(65, $this->art(65, 9));
        $commandOne = $presenter->forArt(69, $this->art(69, 1));

        $this->assertSame('照準 +4', $aimOne['resource_text']);
        $this->assertStringContainsString('MISSで照準+2', implode('|', $aimOne['effect_texts']));
        $this->assertSame('照準 4消費', $aimFive['resource_text']);
        $this->assertStringContainsString('命中 +5pt', implode('|', $aimFive['effect_texts']));
        $this->assertStringContainsString('最大SPの3%', implode('|', $aimFive['effect_texts']));
        $this->assertSame('照準 12消費', $aimNine['resource_text']);
        $this->assertSame(570, $aimNine['effective_power']);
        $this->assertStringContainsString('命中 +8pt', implode('|', $aimNine['effect_texts']));
        $this->assertStringContainsString('最大SPの5%', implode('|', $aimNine['effect_texts']));
        $this->assertStringContainsString('累計15%', implode('|', $aimNine['effect_texts']));

        $this->assertNull($commandOne['resource_text']);
        $this->assertStringNotContainsString('指揮点 +', json_encode($commandOne, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('通常攻撃HIT：指揮点+4', implode('|', $commandOne['effect_texts']));
        $this->assertStringContainsString('合計+5', implode('|', $commandOne['effect_texts']));

        $commandNine = $presenter->forArt(69, $this->art(69, 9));
        $this->assertSame(455, $commandNine['effective_power']);
    }

    public function test_hud_uses_actual_normal_miss_gain_and_structured_target_sp_pressure(): void
    {
        [$aim, $target, $state] = $this->battle(65);
        $resources = app(JobArtV2ResourceService::class);
        $hud = app(JobArtV2BattleHudService::class);

        $resources->beginAction($aim, $state);
        $resources->recordNormalAttackResolution($aim, $target, $state, HitResult::MISS);
        $resources->finishAction($aim, $state);

        $rankFive = $this->art(65, 5);
        $aim->jobArtOrigins[(int) $rankFive->id] = 'current';
        $resources->beginAction($aim, $state);
        $resources->applyJobArtCast($aim, $state, $rankFive);
        $hud->recordHitResult($aim, $state, HitResult::HIT);
        app(JobArtV2SpPressureService::class)->applyOnHit($aim, $target, $state, $rankFive, HitResult::HIT);
        $resources->finishAction($aim, $state);

        $payload = $hud->present($state);
        $this->assertSame('MISS', $payload['actions'][0]['hit_result']);
        $this->assertSame(2, collect($payload['actions'][0]['changes'])->firstWhere('type', 'resource')['delta']);
        $targetSp = collect($payload['actions'][1]['changes'])->firstWhere('type', 'target_sp');
        $this->assertSame([1_000, 970, 30], [$targetSp['before'], $targetSp['after'], $targetSp['actual_loss']]);

        $blade = file_get_contents(resource_path('views/battle/partials/job-art-v2-hud.blade.php'));
        $this->assertStringContainsString("'target_sp'", $blade);
        $this->assertStringContainsString('対象SP', $blade);
    }

    public function test_all_six_battle_paths_wrap_existing_normal_results_and_finalize_actual_actions(): void
    {
        foreach ([
            'app/Services/BattleService.php',
            'app/Services/PvPBattleService.php',
            'app/Services/ChampBattleService.php',
            'app/Services/ArenaNpcBattleService.php',
        ] as $path) {
            $source = file_get_contents(base_path($path));
            $this->assertStringContainsString('recordNormalAttackResolution', $source, $path);
            $this->assertStringContainsString('finishAction', $source, $path);
        }

        $this->assertStringContainsString('extends BattleService', file_get_contents(base_path('app/Services/TowerBattleService.php')));
        $battleService = file_get_contents(base_path('app/Services/BattleService.php'));
        $this->assertStringContainsString("$" . "battleContext = $" . "enemy->is_boss ? 'boss' : 'pve'", $battleService);
        $this->assertStringContainsString('$target', file_get_contents(base_path('app/Services/JobArtBattleSupportService.php')));
    }

    public function test_flag_off_and_untrusted_origins_do_not_show_or_apply_second_wave_effects(): void
    {
        $rankFive = $this->art(65, 5);
        $rankFive->setAttribute('job_art_origin', 'inherited');
        $display = app(JobArtV2LoadoutPresenter::class)->forArt(65, $rankFive);
        $this->assertSame([], $display['effect_texts']);

        config(['battle.job_art_v2.loadout_v2' => false]);
        $this->assertNull(app(JobArtV2LoadoutPresenter::class)->forArt(65, $this->art(65, 5)));

        config(['battle.job_art_v2.resources' => false]);
        [$actor, $target, $state] = $this->battle(65);
        $rankFive = $this->art(65, 5);
        $actor->jobArtOrigins[(int) $rankFive->id] = 'current';
        $state->beginSourceAction();
        $this->assertFalse(app(JobArtV2SpPressureService::class)->applyOnHit($actor, $target, $state, $rankFive, HitResult::HIT)->applied);
        $this->assertSame(1_000, $target->mp);
    }

    /** @return array{BattleActor, BattleActor, BattleState} */
    private function battle(int $jobId): array
    {
        $actor = $this->actor('player', true, $jobId);
        $target = $this->actor('enemy', false, null);

        return [$actor, $target, new BattleState($actor, $target)];
    }

    private function actor(string $name, bool $isPlayer, ?int $jobId): BattleActor
    {
        return new BattleActor($name, $isPlayer, [
            'hp' => 10_000,
            'max_hp' => 10_000,
            'mp' => 1_000,
            'max_mp' => 1_000,
            'agi' => 100,
            'current_job_id' => $jobId,
        ]);
    }

    private function art(int $jobId, int $rank): Skill
    {
        $skill = new Skill([
            'name' => "job-{$jobId}-rank-{$rank}",
            'skill_type' => 'job_art',
            'job_id' => $jobId,
            'learn_rank' => $rank,
            'power' => [1 => 225, 5 => 285, 9 => 355][$rank],
            'effect_template' => $jobId === 65 ? 'MAGICAL_DAMAGE' : 'PHYSICAL_DAMAGE',
        ]);
        $skill->setAttribute('id', ($jobId * 100) + $rank);
        $skill->setAttribute('job_art_origin', 'current');

        return $skill;
    }
}
