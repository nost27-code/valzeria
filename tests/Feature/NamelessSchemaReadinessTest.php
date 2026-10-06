<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\PlayerNamelessEquipment;
use App\Models\User;
use App\Services\NamelessPreparationService;
use App\Services\NamelessSchemaService;
use App\Services\NamelessWorkshopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NamelessSchemaReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_normal_schema_inspection_is_read_only_with_existing_assets(): void
    {
        $body = $this->asset();
        $before = $body->fresh()->getRawOriginal();
        $this->assertSame([], app(NamelessSchemaService::class)->problems());
        $this->assertTrue(app(NamelessPreparationService::class)->status()['ready']);
        $this->assertSame($before, $body->fresh()->getRawOriginal());
    }

    #[DataProvider('requiredColumns')]
    public function test_missing_required_column_blocks_runtime_and_preparation(string $table, string $column): void
    {
        $body = $this->asset();
        $before = $body->fresh()->getRawOriginal();
        if (in_array($column, ['effect_key', 'rank'], true)) {
            Schema::table('player_relics', fn ($blueprint) => $blueprint->dropIndex('player_relic_owner_effect'));
        }
        Schema::table($table, fn ($blueprint) => $blueprint->dropColumn($column));
        config(['nameless_relics.enabled' => true]);
        $problem = $table.':column_missing:'.$column;
        $this->assertContains($problem, app(NamelessPreparationService::class)->status()['schema_problems']);
        $this->assertFalse(app(NamelessWorkshopService::class)->ready());
        config(['nameless_relics.enabled' => false]);
        $this->artisan('nameless:prepare', ['--json' => true])->expectsOutputToContain($problem)->assertFailed();
        $this->artisan('nameless:prepare', ['--register-town' => true])->assertFailed();
        $this->assertSame($before, $body->fresh()->getRawOriginal());
    }

    public static function requiredColumns(): array
    {
        return [
            ['player_relics', 'effect_key'], ['player_relics', 'rank'], ['player_relics', 'is_locked'],
            ['player_relics', 'growth_progress'], ['nameless_workshop_operations', 'action'],
            ['nameless_workshop_operations', 'payload_hash'], ['nameless_workshop_operations', 'result'],
            ['nameless_equipment_discoveries', 'kind'], ['nameless_ruin_progress', 'unlocked_depth'],
        ];
    }

    public function test_wrong_type_nullability_and_defaults_are_rejected(): void
    {
        Schema::table('player_relics', fn ($table) => $table->string('is_locked')->nullable()->default('yes')->change());
        $problems = app(NamelessSchemaService::class)->problems();
        $this->assertContains('player_relics:type:is_locked:expected=bool:actual=varchar', $problems);
        $this->assertContains('player_relics:nullable:is_locked:expected=0', $problems);
        $this->assertContains('player_relics:default:is_locked:expected=0', $problems);
        $this->assertFalse(app(NamelessPreparationService::class)->status()['ready']);
    }

    public function test_missing_tables_fail_closed_without_sql_errors_or_asset_changes(): void
    {
        $body = $this->asset();
        $before = $body->fresh()->getRawOriginal();
        Schema::drop('nameless_workshop_operations');
        config(['nameless_relics.enabled' => true]);
        $this->assertFalse(app(NamelessWorkshopService::class)->ready());
        $this->assertContains('nameless_workshop_operations:table_missing', app(NamelessPreparationService::class)->status()['schema_problems']);
        $this->assertSame($before, $body->fresh()->getRawOriginal());
    }

    public function test_broken_migration_ledger_is_reported_without_a_query_error(): void
    {
        $body = $this->asset();
        $before = $body->fresh()->getRawOriginal();
        Schema::table('migrations', fn ($table) => $table->renameColumn('migration', 'retained_migration'));
        config(['nameless_relics.enabled' => true]);
        $status = app(NamelessPreparationService::class)->status();
        $this->assertContains('migrations:column_missing:migration', $status['schema_problems']);
        $this->assertFalse($status['ready']);
        $this->assertFalse(app(NamelessWorkshopService::class)->ready());
        $this->assertSame($before, $body->fresh()->getRawOriginal());
    }

    public function test_missing_existing_socket_target_table_blocks_activation(): void
    {
        $body = $this->asset();
        $before = $body->fresh()->getRawOriginal();
        Schema::drop('character_items'); // Empty fixture, with retained FK metadata in player_relics.
        config(['nameless_relics.enabled' => true]);
        $this->assertContains('character_items:table_missing', app(NamelessSchemaService::class)->problems());
        $this->assertFalse(app(NamelessWorkshopService::class)->ready());
        $this->assertSame($before, $body->fresh()->getRawOriginal());
    }

    public function test_unmigrated_db_preserves_existing_weapon_and_refuses_activation(): void
    {
        $body = $this->asset();
        $before = $body->fresh()->getRawOriginal();
        foreach (['player_relics', 'nameless_workshop_operations', 'nameless_equipment_discoveries', 'nameless_ruin_progress'] as $table) {
            Schema::drop($table); // Isolated fixture only: no rows in these new tables.
        }
        Schema::table('player_nameless_equipments', fn ($table) => $table->dropColumn(['growth_exp', 'revision']));
        DB::table('migrations')->whereIn('migration', NamelessPreparationService::MIGRATIONS)->delete();
        config(['nameless_relics.enabled' => true]);
        $status = app(NamelessPreparationService::class)->status();
        $this->assertFalse($status['ready']);
        $this->assertFalse(app(NamelessWorkshopService::class)->ready());
        $this->assertCount(5, $status['pending_migrations']);
        $this->assertContains('player_relics:table_missing', $status['schema_problems']);
        $this->assertContains('player_nameless_equipments:column_missing:growth_exp', $status['schema_problems']);
        $this->assertSame(array_diff_key($before, ['growth_exp' => true, 'revision' => true]), $body->fresh()->getRawOriginal());
    }

    public function test_unrecorded_migrations_cannot_open_an_otherwise_complete_schema(): void
    {
        $body = $this->asset();
        $before = $body->fresh()->getRawOriginal();
        DB::table('migrations')->whereIn('migration', NamelessPreparationService::MIGRATIONS)->delete();
        config(['nameless_relics.enabled' => true]);
        $this->assertFalse(app(NamelessWorkshopService::class)->ready());
        $status = app(NamelessPreparationService::class)->status();
        $this->assertCount(5, $status['pending_migrations']);
        $this->assertFalse($status['ready']);
        $this->assertSame($before, $body->fresh()->getRawOriginal());
    }

    private function asset(): PlayerNamelessEquipment
    {
        $character = Character::create(['user_id' => User::factory()->create()->id, 'name' => '保全検証']);
        return PlayerNamelessEquipment::create(['character_id' => $character->id, 'kind' => 'weapon',
            'equipment_type' => '剣', 'custom_name' => '保持する剣', 'forge_level' => 42, 'is_locked' => true]);
    }
}
