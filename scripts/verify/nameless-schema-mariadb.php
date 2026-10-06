<?php

declare(strict_types=1);

// No production/local .env fallback. This mutates fixtures only in the ephemeral CI DB.
if (! in_array('--confirm-isolated-database', $argv, true) || getenv('APP_ENV') !== 'testing'
    || getenv('DB_HOST') !== '127.0.0.1' || ! in_array(getenv('DB_CONNECTION'), ['mysql', 'mariadb'], true)
    || ! preg_match('/\Avalzeria_nation_raid_phase4_[a-z0-9_]+\z/', (string) getenv('DB_DATABASE'))
    || getenv('DB_URL') || getenv('DB_USERNAME') === false || getenv('DB_PASSWORD') === false) {
    fwrite(STDERR, "Explicit testing/loopback/disposable database and confirmation required.\n");
    exit(2);
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/support/NationRaidPhase4MariaDbSafety.php';

use App\Models\Character;
use App\Models\PlayerNamelessEquipment;
use App\Models\PlayerRelic;
use App\Models\User;
use App\Services\NamelessPreparationService;
use App\Services\NamelessSchemaService;
use App\Services\NamelessWorkshopService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$checks = [];
$current = 'preflight';
function schemaRequire(bool $condition, string $reason): void
{
    if (! $condition) { throw new RuntimeException($reason); }
}
function assets(): array
{
    $rows = [];
    foreach (['characters', 'character_items', 'migrations', ...array_keys(NamelessSchemaService::COLUMNS)] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }
    return $rows;
}
function schemaFault(string $name, string $fault, string $restore, string $problem): void
{
    global $checks, $current, $inspector;
    $current = $name;
    $before = assets();
    DB::statement($fault);
    try {
        $status = app(NamelessPreparationService::class)->status();
        schemaRequire(! $status['ready'] && in_array($problem, $status['schema_problems'], true), 'Missing concrete schema diagnostic: '.$problem);
        schemaRequire(in_array($problem, $inspector->problems(), true), 'Reused inspector retained a stale healthy schema.');
        config(['nameless_relics.enabled' => true]);
        schemaRequire(! app(NamelessWorkshopService::class)->ready(), 'Malformed DB opened the feature.');
    } finally {
        config(['nameless_relics.enabled' => false]);
        DB::statement($restore);
    }
    schemaRequire(assets() === $before, 'Schema inspection/refusal lost assets.');
    schemaRequire(app(NamelessPreparationService::class)->status()['ready'], 'Restored DB did not become ready.');
    schemaRequire($inspector->problems() === [], 'Reused inspector retained a stale schema fault.');
    $checks[$name] = ['pass' => true, 'assets_unchanged' => true, 'diagnostic' => $problem];
}
try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $connection = DB::connection();
    NationRaidPhase4MariaDbSafety::settings(app()->environment(), $connection->getConfig(), true, app()->configurationIsCached());
    $version = (string) DB::scalar('SELECT VERSION()');
    $engines = DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::scalar('SELECT DATABASE()'))->pluck('ENGINE', 'TABLE_NAME')->all();
    NationRaidPhase4MariaDbSafety::server($version, DB::scalar('SELECT DATABASE()'), getenv('DB_DATABASE'), $engines);
    config(['nameless_relics.enabled' => false]);
    schemaRequire(app(NamelessPreparationService::class)->status()['ready'], 'Normal MariaDB schema is not ready: '.implode(', ', app(NamelessSchemaService::class)->problems()));
    $character = Character::create(['user_id' => User::factory()->create()->id, 'name' => '隔離schema保全', 'money' => 123456]);
    $body = PlayerNamelessEquipment::create(['character_id' => $character->id, 'kind' => 'weapon', 'equipment_type' => '剣', 'custom_name' => '保持する剣', 'forge_level' => 42, 'is_locked' => true]);
    PlayerRelic::create(['character_id' => $character->id, 'effect_key' => 'stat_str', 'rank' => 9, 'growth_progress' => 1, 'nameless_equipment_id' => $body->id, 'slot_number' => 1, 'is_locked' => true]);
    DB::table('nameless_workshop_operations')->insert(['character_id' => $character->id, 'request_uuid' => '123e4567-e89b-42d3-a456-426614174000', 'action' => 'fixture', 'payload_hash' => str_repeat('a', 64), 'result' => '{"retained":true}']);
    $before = assets();
    $inspector = app(NamelessSchemaService::class);
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        schemaRequire($inspector->problems() === [], 'Normal asset DB rejected.');
        $queries = DB::getQueryLog();
        schemaRequire(count($queries) === 8, 'Full native inspection must use six metadata queries, current database and migration history.');
        schemaRequire(count(array_filter($queries, fn ($query) => str_contains(strtolower($query['query']), 'information_schema.'))) === 6, 'Metadata query budget exceeded.');
        schemaRequire(! collect($queries)->contains(fn ($query) => str_contains(strtolower($query['query']), 'join information_schema.')), 'Catalog joins must not scan unrelated hosted databases.');
        schemaRequire($inspector->problems() === [] && count(DB::getQueryLog()) === 16, 'Repeated inspection must re-read schema and history.');
        DB::flushQueryLog();
        schemaRequire(! app(NamelessWorkshopService::class)->ready() && DB::getQueryLog() === [], 'OFF runtime gate must not inspect the DB.');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    $checks['fresh_batched_inspection_query_budget'] = ['pass' => true, 'queries_per_inspection' => 8, 'metadata_queries' => 6, 'off_gate_queries' => 0];
    schemaRequire(assets() === $before, 'Read-only normal inspection changed assets.');
    $checks['normal_assets_read_only'] = ['pass' => true];

    schemaFault('broken_migration_ledger', 'ALTER TABLE migrations CHANGE COLUMN migration retained_migration VARCHAR(255) NOT NULL',
        'ALTER TABLE migrations CHANGE COLUMN retained_migration migration VARCHAR(255) NOT NULL', 'migrations:column_missing:migration');
    schemaFault('missing_effect_key_preserves_values', 'ALTER TABLE player_relics CHANGE COLUMN effect_key retained_effect_key VARCHAR(64) NOT NULL',
        'ALTER TABLE player_relics CHANGE COLUMN retained_effect_key effect_key VARCHAR(64) NOT NULL', 'player_relics:column_missing:effect_key');
    schemaFault('missing_operation_action_preserves_audit', 'ALTER TABLE nameless_workshop_operations CHANGE COLUMN action retained_action VARCHAR(32) NOT NULL',
        'ALTER TABLE nameless_workshop_operations CHANGE COLUMN retained_action action VARCHAR(32) NOT NULL', 'nameless_workshop_operations:column_missing:action');
    schemaFault('wrong_rank_type', 'ALTER TABLE player_relics MODIFY COLUMN rank VARCHAR(3) NOT NULL',
        'ALTER TABLE player_relics MODIFY COLUMN rank TINYINT UNSIGNED NOT NULL', 'player_relics:type:rank:expected=tinyint:actual=varchar(3)');
    // MariaDB 10.5 JSON uses a column CHECK; replace its column definition, without deleting values.
    schemaFault('missing_json_constraint', 'ALTER TABLE nameless_workshop_operations MODIFY COLUMN result LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL',
        'ALTER TABLE nameless_workshop_operations MODIFY COLUMN result JSON NOT NULL', 'nameless_workshop_operations:type:result:expected=json:actual=longtext');
    schemaFault('weakened_json_constraint', 'ALTER TABLE nameless_workshop_operations MODIFY COLUMN result LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (JSON_VALID(result) OR 1)',
        'ALTER TABLE nameless_workshop_operations MODIFY COLUMN result JSON NOT NULL', 'nameless_workshop_operations:type:result:expected=json:actual=longtext');
    schemaFault('missing_table_preserves_storage', 'RENAME TABLE nameless_equipment_discoveries TO retained_nameless_discoveries',
        'RENAME TABLE retained_nameless_discoveries TO nameless_equipment_discoveries', 'nameless_equipment_discoveries:table_missing');
    schemaFault('missing_existing_socket_target', 'RENAME TABLE character_items TO retained_character_items',
        'RENAME TABLE retained_character_items TO character_items', 'character_items:table_missing');
    DB::statement('CREATE INDEX fixture_socket_fk ON player_relics (character_item_id, slot_number)');
    try {
        schemaFault('missing_socket_unique', 'ALTER TABLE player_relics DROP INDEX player_relic_ordinary_socket_unique',
            'ALTER TABLE player_relics ADD UNIQUE player_relic_ordinary_socket_unique (character_item_id, slot_number)', 'player_relics:unique:character_item_id,slot_number');
    } finally {
        DB::statement('DROP INDEX fixture_socket_fk ON player_relics');
    }
    schemaFault('wrong_socket_foreign_delete_rule',
        'ALTER TABLE player_relics DROP FOREIGN KEY player_relics_character_item_id_foreign, ADD CONSTRAINT fixture_wrong_socket_delete_rule FOREIGN KEY (character_item_id) REFERENCES character_items(id) ON DELETE RESTRICT',
        'ALTER TABLE player_relics DROP FOREIGN KEY fixture_wrong_socket_delete_rule, ADD CONSTRAINT player_relics_character_item_id_foreign FOREIGN KEY (character_item_id) REFERENCES character_items(id) ON DELETE SET NULL',
        'player_relics:foreign:character_item_id:expected=character_items.id/set null');
    $current = 'migration_history_missing';
    $before = assets();
    DB::beginTransaction();
    try {
        DB::table('migrations')->whereIn('migration', NamelessPreparationService::MIGRATIONS)->delete();
        config(['nameless_relics.enabled' => true]);
        $status = app(NamelessPreparationService::class)->status();
        schemaRequire(count($status['pending_migrations']) === 5 && ! $status['ready'] && ! app(NamelessWorkshopService::class)->ready(), 'Unrecorded migrations opened the feature.');
    } finally {
        DB::rollBack();
        config(['nameless_relics.enabled' => false]);
    }
    schemaRequire(assets() === $before && app(NamelessPreparationService::class)->status()['ready'], 'Migration inspection changed assets.');
    $checks[$current] = ['pass' => true, 'assets_unchanged' => true];
    $current = 'unmigrated_db_existing_assets';
    $before = assets();
    $tables = ['player_relics', 'nameless_workshop_operations', 'nameless_equipment_discoveries', 'nameless_ruin_progress'];
    DB::statement('RENAME TABLE '.implode(', ', array_map(fn ($table) => $table.' TO retained_'.$table, $tables)));
    try {
        DB::beginTransaction();
        try {
            DB::table('migrations')->whereIn('migration', NamelessPreparationService::MIGRATIONS)->delete();
            config(['nameless_relics.enabled' => true]);
            $status = app(NamelessPreparationService::class)->status();
            schemaRequire(count($status['pending_migrations']) === 5 && ! $status['ready'] && ! app(NamelessWorkshopService::class)->ready(), 'Unmigrated DB opened the feature.');
            foreach ($tables as $table) {
                schemaRequire(in_array($table.':table_missing', $status['schema_problems'], true), 'Missing unmigrated table diagnostic: '.$table);
            }
        } finally {
            DB::rollBack();
            config(['nameless_relics.enabled' => false]);
        }
    } finally {
        DB::statement('RENAME TABLE '.implode(', ', array_map(fn ($table) => 'retained_'.$table.' TO '.$table, $tables)));
    }
    schemaRequire(assets() === $before && app(NamelessPreparationService::class)->status()['ready'], 'Unmigrated inspection lost assets.');
    $checks[$current] = ['pass' => true, 'assets_unchanged' => true];
    echo json_encode(['pass' => true, 'server_version' => $version, 'driver' => DB::getDriverName(), 'checks' => $checks, 'scope' => 'Ephemeral CI only; no production data or setting changed.'], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;
} catch (Throwable $exception) {
    echo json_encode(['pass' => false, 'failed_check' => $current, 'checks' => $checks, 'error_class' => $exception::class,
        'database_error_code' => $exception->errorInfo[1] ?? null,
        'error_statement' => $exception instanceof \Illuminate\Database\QueryException ? $exception->getSql() : null,
        'error' => $exception::class === RuntimeException::class ? $exception->getMessage() : 'Inspect the named schema check.'], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;
    exit(1);
}
