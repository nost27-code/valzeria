<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\User;
use App\Services\CharacterStatusService;
use App\Services\NamelessPreparationService;
use App\Services\NamelessSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NamelessSchemaSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_nested_read_operation_uses_one_inspection_and_next_operation_is_fresh(): void
    {
        $schema = app(NamelessSchemaService::class);
        $this->assertSame($schema, app(NamelessSchemaService::class));
        DB::enableQueryLog();
        $schema->withSnapshot(function () use ($schema) {
            $this->assertSame([], $schema->problems());
            $firstQueries = count(DB::getQueryLog());
            $schema->withSnapshot(fn () => $this->assertSame([], $schema->problems()));
            $this->assertSame($firstQueries, count(DB::getQueryLog()));
        });
        DB::disableQueryLog();
        DB::table('migrations')->where('migration', NamelessPreparationService::MIGRATIONS[0])->delete();
        $this->assertContains('migrations:pending:'.NamelessPreparationService::MIGRATIONS[0], $schema->withSnapshot(fn () => $schema->problems()));
    }

    public function test_exception_discards_the_snapshot_and_transaction_changes_never_escape(): void
    {
        $schema = app(NamelessSchemaService::class);
        DB::beginTransaction();
        try {
            DB::table('migrations')->where('migration', NamelessPreparationService::MIGRATIONS[0])->delete();
            $schema->withSnapshot(function () use ($schema) {
                $this->assertNotSame([], $schema->problems());
                throw new \RuntimeException('read-operation sentinel');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('read-operation sentinel', $exception->getMessage());
        } finally {
            DB::rollBack();
        }
        $this->assertSame([], $schema->problems());
    }

    public function test_final_stats_inspect_schema_once_without_changing_existing_assets(): void
    {
        config(['nameless_relics.enabled' => true]);
        $character = Character::create(['user_id' => User::factory()->create()->id, 'name' => 'Snapshot probe']);
        $character = $character->fresh();
        $before = $character->fresh()->getRawOriginal();
        DB::enableQueryLog();
        $stats = app(CharacterStatusService::class)->getFinalStats($character);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $migrationReads = array_filter($queries, fn ($q) => str_contains($q['query'], '"migrations"') && str_starts_with($q['query'], 'select'));
        $this->assertCount(1, $migrationReads);
        $this->assertSame($before, $character->fresh()->getRawOriginal());
        CharacterStatusService::clearRequestCache();
        $this->assertSame($stats, app(CharacterStatusService::class)->getFinalStats($character->fresh()));
    }
}
