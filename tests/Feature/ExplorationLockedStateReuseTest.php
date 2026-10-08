<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Character;
use App\Models\User;
use App\Services\ExplorationStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExplorationLockedStateReuseTest extends TestCase
{
    use RefreshDatabase;

    private int $firstArea;

    private int $secondArea;

    protected function setUp(): void
    {
        parent::setUp();
        $area = Area::query()->firstOrFail();
        $this->firstArea = (int) $area->id;
        $second = $area->replicate();
        $second->slug = 'state-reuse-second-'.uniqid();
        $second->save();
        $this->secondArea = (int) $second->id;
    }

    public function test_locked_reads_are_shared_but_saved_state_and_unsaved_edits_stay_correct(): void
    {
        $hero = $this->hero();
        $service = app(ExplorationStateService::class);
        $state = $service->getOrStart($hero, $this->firstArea);
        $other = $this->hero();
        $service->getOrStart($other, $this->firstArea);
        DB::enableQueryLog();
        $service->withLockedState($hero, function () use ($service, $hero, $other, $state): void {
            $first = $service->currentFor($hero);
            $first->exploration_point = 999;
            for ($i = 0; $i < 50; $i++) {
                $this->assertSame(0, (int) $service->currentFor($hero)->exploration_point);
            }
            $reads = array_filter(DB::getQueryLog(), fn ($q) => str_starts_with($q['query'], 'select') && str_contains($q['query'], 'character_exploration_states'));
            $this->assertCount(1, $reads);
            // A write made outside this service still invalidates its cached read.
            $state->update(['exploration_point' => 8]);
            $this->assertSame(8, (int) $service->currentFor($hero)->exploration_point);
            $this->assertSame(8, (int) $service->getOrStart($hero, $this->firstArea)->exploration_point);
            DB::table('character_exploration_states')->where('id', $state->id)->update(['exploration_point' => 10]);
            $this->assertSame(10, (int) $service->currentFor($hero)->exploration_point);
            $this->assertSame((int) $other->id, (int) $service->currentFor($other)->character_id);
            $state->delete();
            $this->assertNull($service->currentFor($hero));
            $service->getOrStart($hero, $this->firstArea);
            $this->assertNotNull($service->currentFor($hero));
        });
        DB::disableQueryLog();
        $state = $service->currentFor($hero);
        $state->update(['exploration_point' => 12]);
        $this->assertSame(12, (int) $service->currentFor($hero)->exploration_point);
    }

    public function test_rollback_and_exception_do_not_leave_a_cached_state(): void
    {
        $hero = $this->hero();
        $service = app(ExplorationStateService::class);
        $service->getOrStart($hero, $this->firstArea);
        $service->withLockedState($hero, function () use ($service, $hero): void {
            DB::beginTransaction();
            $state = $service->currentFor($hero);
            $state->update(['exploration_point' => 90]);
            $this->assertSame(90, (int) $service->currentFor($hero)->exploration_point);
            DB::rollBack();
            $this->assertSame(0, (int) $service->currentFor($hero)->exploration_point);
        });
        try {
            $service->withLockedState($hero, function () use ($service, $hero): void {
                $service->currentFor($hero);
                throw new \RuntimeException('test exception');
            });
            $this->fail('Exception expected');
        } catch (\RuntimeException $e) {
            $this->assertSame('test exception', $e->getMessage());
        }
        $state = $service->currentFor($hero);
        $state->update(['danger_rate' => 10]);
        $this->assertSame(10, (int) $service->currentFor($hero)->danger_rate);
    }

    public function test_missing_state_creation_and_area_switch_are_read_fresh(): void
    {
        $hero = $this->hero();
        $service = app(ExplorationStateService::class);
        $service->withLockedState($hero, function () use ($service, $hero): void {
            $this->assertNull($service->currentFor($hero));
            $first = $service->getOrStart($hero, $this->firstArea);
            $this->assertSame((int) $first->id, (int) $service->currentFor($hero)->id);
            $first->update(['exploration_point' => 8]);
            $second = $service->getOrStart($hero, $this->secondArea);
            $this->assertSame($this->secondArea, (int) $second->area_id);
            $this->assertSame(0, (int) $service->currentFor($hero)->exploration_point);
            $this->assertSame($this->secondArea, (int) $service->currentFor($hero)->area_id);
        });
    }

    private function hero(): Character
    {
        return Character::create(['user_id' => User::factory()->create()->id, 'name' => '状態共有試験']);
    }
}
