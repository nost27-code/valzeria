<?php

namespace Tests\Feature;

use App\Livewire\Admin\BattleSimulator;
use App\Models\Character;
use App\Models\CharacterJob;
use App\Models\Enemy;
use App\Models\JobClass;
use App\Models\User;
use App\Services\Battle\BattleResult;
use App\Services\BattleService;
use App\Services\CharacterStatusService;
use App\Services\HeroTrialBenchmarkService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class HeroTrialBenchmarkServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_mastered_crown_jobs_only_returns_mastered_crown_jobs(): void
    {
        [$character, $masteredCrown, $unmasteredCrown] = $this->benchmarkCharacter();

        $jobs = app(HeroTrialBenchmarkService::class)->masteredCrownJobs($character);

        $this->assertSame([$masteredCrown->id], $jobs->pluck('id')->all());
        $this->assertFalse($jobs->contains('id', $unmasteredCrown->id));
    }

    public function test_preview_uses_virtual_crown_job_without_changing_character(): void
    {
        [$character, $masteredCrown] = $this->benchmarkCharacter();
        $service = app(HeroTrialBenchmarkService::class);

        $actualStats = $service->previewFinalStats($character);
        $virtualStats = $service->previewFinalStats($character, $masteredCrown->id);

        $this->assertGreaterThan($actualStats['str'], $virtualStats['str']);
        $this->assertSame(255, (int) $character->fresh()->level);
        $this->assertNotSame($masteredCrown->id, (int) $character->fresh()->current_job_id);
    }

    public function test_preview_rejects_unmastered_crown_job(): void
    {
        [$character, , $unmasteredCrown] = $this->benchmarkCharacter();

        $this->expectException(DomainException::class);

        app(HeroTrialBenchmarkService::class)->previewFinalStats($character, $unmasteredCrown->id);
    }

    public function test_simulation_rolls_back_virtual_job_change(): void
    {
        [$character, $masteredCrown] = $this->benchmarkCharacter();
        $originalJobId = (int) $character->current_job_id;
        $result = new BattleResult();
        $result->result = 'victory';

        $battleService = Mockery::mock(BattleService::class);
        $battleService->shouldReceive('executeBattle')
            ->once()
            ->withArgs(function (Character $simulatedCharacter, Enemy $enemy) use ($masteredCrown): bool {
                $this->assertSame($masteredCrown->id, (int) $simulatedCharacter->current_job_id);
                $this->assertSame($masteredCrown->id, (int) Character::query()->findOrFail($simulatedCharacter->id)->current_job_id);

                return true;
            })
            ->andReturn($result);

        $service = new HeroTrialBenchmarkService(
            app(CharacterStatusService::class),
            $battleService,
        );

        $actualResult = $service->simulate(
            $character,
            new Enemy(['name' => '仮想試練主']),
            $masteredCrown->id,
            false,
        );

        $this->assertSame('victory', $actualResult->result);
        $this->assertSame($originalJobId, (int) $character->fresh()->current_job_id);
    }

    public function test_admin_simulator_shows_mastered_crown_virtual_job_selector(): void
    {
        [$character, $masteredCrown, $unmasteredCrown] = $this->benchmarkCharacter();
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        Livewire::test(BattleSimulator::class)
            ->call('selectCharacter', $character->id)
            ->assertSee('仮想職業')
            ->assertSee($masteredCrown->name)
            ->assertDontSee($unmasteredCrown->name)
            ->set('virtualJobId', $masteredCrown->id)
            ->assertSee('実職業:')
            ->assertSee('実転職時のLv1化・基礎能力圧縮は適用しません')
            ->set('virtualJobId', '')
            ->assertSet('virtualJobId', null);
    }

    public function test_admin_simulator_route_rejects_non_admin_user(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'user']))
            ->get(route('admin.battle-simulator'))
            ->assertRedirect('/admin/login');
    }

    public function test_admin_simulator_rejects_direct_unmastered_crown_selection(): void
    {
        [$character, , $unmasteredCrown] = $this->benchmarkCharacter();
        $enemy = Enemy::query()->firstOrFail();
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        Livewire::test(BattleSimulator::class)
            ->call('selectCharacter', $character->id)
            ->call('selectEnemy', $enemy->id)
            ->set('virtualJobId', $unmasteredCrown->id)
            ->set('simulationCount', 1)
            ->call('runSimulation')
            ->assertHasErrors(['virtualJobId']);

        $this->assertNotSame($unmasteredCrown->id, (int) $character->fresh()->current_job_id);
    }

    /**
     * @return array{Character, JobClass, JobClass}
     */
    private function benchmarkCharacter(): array
    {
        $currentJob = JobClass::query()->create([
            'key' => 'benchmark_current_job',
            'name' => '検証中級職',
            'rank' => 'middle',
            'sort_order' => 10,
        ]);
        $masteredCrown = JobClass::query()->create([
            'key' => 'benchmark_mastered_crown',
            'name' => '検証冠位職',
            'rank' => 'crown',
            'bonus_str' => 50,
            'sort_order' => 20,
        ]);
        $unmasteredCrown = JobClass::query()->create([
            'key' => 'benchmark_unmastered_crown',
            'name' => '未修得冠位職',
            'rank' => 'crown',
            'bonus_str' => 100,
            'sort_order' => 30,
        ]);

        $character = Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => '英雄試練基準者',
            'level' => 255,
            'current_job_id' => $currentJob->id,
            'hp_base' => 1000,
            'mp_base' => 300,
            'attack_base' => 500,
            'defense_base' => 400,
            'speed_base' => 350,
            'magic_base' => 300,
            'spirit_base' => 300,
            'luck_base' => 200,
            'current_hp' => 1000,
            'current_mp' => 300,
        ]);

        CharacterJob::query()->create([
            'character_id' => $character->id,
            'job_class_id' => $currentJob->id,
            'job_level' => 10,
            'job_exp' => 0,
            'is_mastered' => true,
        ]);
        CharacterJob::query()->create([
            'character_id' => $character->id,
            'job_class_id' => $masteredCrown->id,
            'job_level' => 10,
            'job_exp' => 0,
            'is_mastered' => true,
        ]);
        CharacterJob::query()->create([
            'character_id' => $character->id,
            'job_class_id' => $unmasteredCrown->id,
            'job_level' => 9,
            'job_exp' => 0,
            'is_mastered' => false,
        ]);

        return [$character, $masteredCrown, $unmasteredCrown];
    }
}
