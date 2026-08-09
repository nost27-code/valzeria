<?php

namespace Tests\Unit;

use App\Http\Controllers\JobArtController;
use App\Models\Character;
use App\Models\CharacterJobArtSlot;
use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\JobArtBattleSupportService;
use App\Services\JobArtService;
use App\Services\JobArtV2BattleRules;
use App\Services\JobArtV2LoadoutPresenter;
use App\Services\JobArtV2SelectionService;
use App\Services\JobArtV2SpCostCalculator;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class JobArtV2SpCostIntegrationTest extends TestCase
{
    public function test_ui_display_and_shared_interpersonal_consumption_use_the_same_cost(): void
    {
        config([
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.normalized_sp' => true,
        ]);
        $calculator = app(JobArtV2SpCostCalculator::class);
        $rules = app(JobArtV2BattleRules::class);
        $character = new Character(['current_job_id' => 24]);
        $skill = new Skill([
            'job_id' => 24,
            'name' => '表示一致テスト奥義',
            'skill_type' => 'job_art',
            'learn_rank' => 1,
            'activation_rate' => 87,
            'art_cost' => 1,
            'effect_template' => 'DAMAGE',
        ]);
        $skill->setAttribute('id', 7001);
        $skill->setAttribute('job_art_origin', 'current');
        $skill->setRelation('jobClass', null);

        $decorate = new ReflectionMethod(JobArtController::class, 'decorateArtsForDisplay');
        $decorate->invoke(
            new JobArtController(),
            collect([$skill]),
            $character,
            400,
            $calculator,
            $rules,
            app(JobArtV2LoadoutPresenter::class),
        );

        $actor = new BattleActor('player', true, [
            'hp' => 100,
            'max_hp' => 100,
            'mp' => 100,
            'max_mp' => 400,
            'current_job_id' => 24,
        ]);
        $actor->jobArtOrigins[7001] = 'current';
        $enemy = new BattleActor('enemy', false, ['hp' => 100, 'max_hp' => 100]);
        $state = new BattleState($actor, $enemy);
        $support = new JobArtBattleSupportService(
            Mockery::mock(JobArtService::class),
            app(\App\Services\JobArtV2FeatureGate::class),
            Mockery::mock(JobArtV2SelectionService::class),
            $calculator,
        );

        $this->assertSame(8, $skill->getAttribute('job_art_display_sp_cost'));
        $this->assertSame(35, $skill->getAttribute('job_art_display_activation_rate'));
        $this->assertSame(8, $support->spCost($actor, $skill));

        $support->consumeAndMarkUse($actor, $state, $skill);
        $this->assertSame(92, $actor->mp);

        $slot = new CharacterJobArtSlot([
            'skill_id' => 7001,
            'slot_no' => 1,
            'battle_context' => 'normal',
            'activation_policy' => 'normal',
        ]);

        $html = view('job-arts.partials.slot-card', [
            'slotContext' => 'normal',
            'slotNo' => 1,
            'slot' => $slot,
            'contextArts' => collect([$skill]),
            'allAvailableArts' => collect([$skill]),
            'maxSp' => 400,
            'activationPolicyLabels' => ['aggressive' => '積極', 'normal' => '通常', 'conserve' => '温存'],
            'activationPolicyDescriptions' => ['normal' => 'SPが30%以上ある時だけ発動します'],
            'contextTotalCost' => 1,
            'maxCost' => 9,
        ])->render();

        $this->assertStringContainsString('SP8', $html);
    }

    public function test_inherited_ui_activation_rate_matches_v2_rank_value(): void
    {
        config([
            'battle.job_art_v2.dynamic_single' => true,
            'battle.job_art_v2.normalized_sp' => true,
        ]);
        $character = new Character(['current_job_id' => 24]);
        $skill = new Skill([
            'job_id' => 53,
            'name' => '継承発動率テスト戦技',
            'skill_type' => 'job_art',
            'learn_rank' => 9,
            'activation_rate' => 27,
            'art_cost' => 3,
            'effect_template' => 'MAGICAL_DAMAGE',
        ]);
        $skill->setAttribute('id', 7009);
        $skill->setAttribute('job_art_origin', 'inherited');
        $skill->setRelation('jobClass', null);

        (new ReflectionMethod(JobArtController::class, 'decorateArtsForDisplay'))->invoke(
            new JobArtController(),
            collect([$skill]),
            $character,
            400,
            app(JobArtV2SpCostCalculator::class),
            app(JobArtV2BattleRules::class),
            app(JobArtV2LoadoutPresenter::class),
        );

        $this->assertSame(50, $skill->getAttribute('job_art_display_activation_rate'));
        $this->assertSame(27, $skill->effectiveActivationRate());
    }

    public function test_all_six_battle_paths_and_job_art_ui_are_wired_to_the_common_calculator(): void
    {
        $battle = file_get_contents(base_path('app/Services/BattleService.php'));
        $tower = file_get_contents(base_path('app/Services/TowerBattleService.php'));
        $support = file_get_contents(base_path('app/Services/JobArtBattleSupportService.php'));
        $controller = file_get_contents(base_path('app/Http/Controllers/JobArtController.php'));
        $slotCard = file_get_contents(base_path('resources/views/job-arts/partials/slot-card.blade.php'));

        $this->assertStringContainsString('jobArtV2SpCostCalculator->forActor', $battle);
        $this->assertStringContainsString('class TowerBattleService extends BattleService', $tower);
        $this->assertStringContainsString('jobArtV2SpCostCalculator->forActor', $support);
        foreach (['PvPBattleService.php', 'ChampBattleService.php', 'ArenaNpcBattleService.php'] as $serviceFile) {
            $this->assertStringContainsString(
                'jobArtBattleSupport->consumeAndMarkUse',
                file_get_contents(base_path('app/Services/' . $serviceFile)),
            );
        }
        $this->assertStringContainsString('spCostCalculator->forCharacter', $controller);
        $this->assertStringContainsString("getAttribute('job_art_display_sp_cost')", $slotCard);
    }

    public function test_laravel_container_resolves_pr5_services_and_all_battle_entry_services(): void
    {
        foreach ([
            JobArtV2SpCostCalculator::class,
            JobArtV2BattleRules::class,
            \App\Services\BattleService::class,
            \App\Services\TowerBattleService::class,
            \App\Services\PvPBattleService::class,
            \App\Services\ChampBattleService::class,
            \App\Services\ArenaNpcBattleService::class,
        ] as $service) {
            $this->assertInstanceOf($service, app($service));
        }
    }
}
