<?php
namespace Tests\Feature;

use App\Http\Middleware\CommitExplorationRequest;
use App\Models\{Character, User, PlayerNamelessEquipment};
use App\Services\{NamelessSchemaService, NamelessWorkshopService, NamelessPreparationService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BattleSchemaOperationSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_inspection_per_operation_keeps_owned_equipment_fresh_and_replay_safe(): void
    {
        config(['nameless_relics.enabled' => true]);
        $hero = Character::create(['user_id' => User::factory()->create()->id, 'name' => '構成確認試験']);
        $gear = PlayerNamelessEquipment::create(['character_id' => $hero->id, 'kind' => 'weapon', 'equipment_type' => '剣', 'is_equipped' => true]);
        $token = (string) Str::uuid();
        $middleware = app(CommitExplorationRequest::class);
        DB::enableQueryLog();
        $middleware->handle($this->request($hero, $token), function () use ($hero, $gear) {
            $service = app(NamelessWorkshopService::class);
            $before = $service->equippedBonuses($hero)['str'];
            for ($i = 0; $i < 50; $i++) {
                if ($i === 25) $gear->update(['forge_level' => 1]);
                $actual = $service->equippedBonuses($hero)['str'];
                $this->assertTrue($i < 25 ? $actual === $before : $actual > $before);
            }
            return redirect('/schema-result');
        });
        $reads = array_filter(DB::getQueryLog(), fn ($q) => str_starts_with($q['query'], 'select') && preg_match('/from ["`]?migrations["`]?/i', $q['query']));
        DB::disableQueryLog();
        $this->assertCount(1, $reads);
        $this->assertDatabaseCount('exploration_requests', 1);
        $middleware->handle($this->request($hero, $token), fn () => $this->fail('Replay executed exploration'));
        $this->assertSame(1, $gear->fresh()->forge_level);
        DB::table('migrations')->where('migration', NamelessPreparationService::MIGRATIONS[0])->delete();
        $middleware->handle($this->request($hero, (string) Str::uuid()), function () {
            $this->assertFalse(app(NamelessWorkshopService::class)->ready());
            return redirect('/schema-result');
        });
    }

    public function test_failed_operation_discards_schema_snapshot_and_all_writes(): void
    {
        config(['nameless_relics.enabled' => true]);
        $hero = Character::create(['user_id' => User::factory()->create()->id, 'name' => '巻戻し試験', 'money' => 0]);
        $token = (string) Str::uuid();
        try {
            app(CommitExplorationRequest::class)->handle($this->request($hero, $token), function () use ($hero) {
                $hero->update(['money' => 99]);
                session(['schema_failed' => true]);
                throw new \RuntimeException('sentinel');
            });
            $this->fail('Expected failure');
        } catch (\RuntimeException $e) { $this->assertSame('sentinel', $e->getMessage()); }
        $this->assertSame(0, (int) $hero->fresh()->money);
        $this->assertFalse(session()->has('schema_failed'));
        $this->assertDatabaseCount('exploration_requests', 0);
        DB::table('migrations')->where('migration', NamelessPreparationService::MIGRATIONS[0])->delete();
        $this->assertNotEmpty(app(NamelessSchemaService::class)->withBattleSnapshot(fn () => app(NamelessSchemaService::class)->problems()));
    }

    private function request(Character $hero, string $token): Request
    {
        $request = Request::create('/battle/areas/1/explore', 'POST', ['exploration_request_id' => $token, 'batch_count' => 50]);
        $request->setLaravelSession(app('session')->driver());
        $this->app->instance('request', $request);
        $request->setUserResolver(fn () => $hero->user);
        session(['current_character_id' => $hero->id]);
        return $request;
    }
}
