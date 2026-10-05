<?php

declare(strict_types=1);

// This script creates fixtures only in a disposable loopback database. It never reads .env credentials.
if (! in_array('--confirm-isolated-database', $argv, true)
    || getenv('APP_ENV') !== 'testing'
    || getenv('DB_HOST') !== '127.0.0.1'
    || ! in_array(getenv('DB_CONNECTION'), ['mysql', 'mariadb'], true)
    || getenv('DB_USERNAME') === false || getenv('DB_PASSWORD') === false
    || preg_match('/\Avalzeria_nameless_verify_[a-z0-9_]+\z/', (string) getenv('DB_DATABASE')) !== 1
    || ! ctype_digit((string) getenv('DB_PORT')) || (int) getenv('DB_PORT') < 1024
    || (int) getenv('DB_PORT') > 65535 || getenv('DB_PORT') === '3306'
    || getenv('DB_URL')) {
    fwrite(STDERR, "Explicit testing/loopback/disposable database settings and confirmation required.\n");
    exit(2);
}

require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Models\Character;
use App\Models\PlayerRelic;
use App\Models\User;
use App\Services\NamelessPreparationService;
use App\Services\NamelessRelicGrowthService;
use App\Services\NamelessTownService;
use App\Services\NamelessWorkshopService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

$checks = [];
$currentCheck = 'isolation';
function requireCheck(bool $ok, string $message): void
{
    if (! $ok) {
        throw new RuntimeException($message);
    }
}

/** Two independently booted PHP processes start at the same barrier. */
function race(string $kind, array $payloads): array
{
    $children = [];
    foreach ($payloads as $payload) {
        $process = proc_open([PHP_BINARY, __FILE__, 'worker', $kind,
            base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), '--confirm-isolated-database'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
        requireCheck(is_resource($process), 'Worker did not start.');
        $children[] = [$process, $pipes];
    }
    try {
        foreach ($children as [, $pipes]) {
            requireCheck(trim((string) fgets($pipes[1])) === 'ready', 'Worker did not reach barrier.');
        }
        foreach ($children as [, $pipes]) {
            fwrite($pipes[0], "go\n");
            fclose($pipes[0]);
        }
        $results = [];
        foreach ($children as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]); // Never print credentials, bindings or player data.
            fclose($pipes[1]); fclose($pipes[2]);
            requireCheck(proc_close($process) === 0, 'Worker process failed.');
            $results[] = json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);
        }
        return $results;
    } finally {
        foreach ($children as [$process, $pipes]) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) { fclose($pipe); }
            }
            if (is_resource($process)) { proc_terminate($process); proc_close($process); }
        }
    }
}

try {
    $database = (string) getenv('DB_DATABASE');
    if (($argv[1] ?? 'all') !== 'worker') {
        $pdo = new PDO('mysql:host=127.0.0.1;port='.(int) getenv('DB_PORT').';charset=utf8mb4',
            (string) getenv('DB_USERNAME'), (string) getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        requireCheck(preg_match('/^(?:5\.5\.5-)?(\d+\.\d+\.\d+).*MariaDB/i', $version, $matches) === 1
            && version_compare($matches[1], '10.5.13', '>='), 'MariaDB 10.5.13 or newer required.');
        $query = $pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');
        $query->execute([$database]);
        requireCheck((int) $query->fetchColumn() === 0, 'Refusing to reuse an existing verification database.');
        $pdo->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    requireCheck(! $app->configurationIsCached(), 'Cached config is not allowed.');
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    requireCheck($app->environment('testing'), 'Environment changed.');
    $connection = config('database.connections.'.config('database.default'));
    requireCheck(in_array($connection['driver'], ['mysql', 'mariadb'], true)
        && $connection['host'] === '127.0.0.1' && (int) $connection['port'] === (int) getenv('DB_PORT')
        && $connection['database'] === $database && empty($connection['url'])
        && empty($connection['read']) && empty($connection['write']) && empty($connection['prefix']), 'Connection redirect refused.');
    requireCheck(DB::selectOne('SELECT DATABASE() AS name')->name === $database, 'Connected database mismatch.');
    config(['nameless_relics.enabled' => false]);

    if (($argv[1] ?? 'all') === 'worker') {
        $kind = $argv[2];
        $payload = json_decode(base64_decode($argv[3], true), true, flags: JSON_THROW_ON_ERROR);
        fwrite(STDOUT, "ready\n"); fflush(STDOUT);
        requireCheck(trim((string) fgets(STDIN)) === 'go', 'Barrier cancelled.');
        try {
            if ($kind === 'town') {
                $result = ['town_id' => app(NamelessTownService::class)->installTown(true)->id];
            } else {
                requireCheck($kind === 'grow', 'Unsupported worker.');
                config(['nameless_relics.enabled' => true]); // This process and fixture DB only.
                $result = app(NamelessRelicGrowthService::class)->grow(Character::findOrFail($payload['character']),
                    $payload['target'], $payload['sources'], $payload['hash'], $payload['uuid']);
            }
            fwrite(STDOUT, json_encode(['success' => true, 'result' => $result], JSON_THROW_ON_ERROR));
        } catch (Throwable $exception) {
            fwrite(STDOUT, json_encode(['success' => false, 'error_class' => $exception::class], JSON_THROW_ON_ERROR));
        }
        exit(0);
    }

    $currentCheck = 'baseline_migrations';
    $paths = [];
    foreach (glob(database_path('migrations/*.php')) as $path) {
        if (! in_array(basename($path, '.php'), NamelessPreparationService::MIGRATIONS, true)) {
            $paths[] = $path;
        }
    }
    ob_start();
    try {
        requireCheck(Artisan::call('migrate', ['--path' => $paths, '--realpath' => true, '--force' => true]) === 0,
            'Baseline migrations failed.');
    } finally {
        ob_end_clean(); // Legacy migration messages must not corrupt the JSON evidence.
    }
    $character = Character::create(['user_id' => User::factory()->create()->id, 'name' => '隔離遺物検証',
        'current_city_id' => 1, 'highest_city_id' => 1, 'hp_base' => 10000, 'mp_base' => 1000,
        'current_hp' => 5000, 'current_mp' => 300, 'attack_base' => 1000, 'defense_base' => 1000,
        'magic_base' => 1000, 'spirit_base' => 1000, 'speed_base' => 1000, 'luck_base' => 10, 'money' => 123456]);
    $body = DB::table('player_nameless_equipments')->insertGetId(['character_id' => $character->id,
        'kind' => 'weapon', 'custom_name' => '移行前の武器', 'equipment_type' => '剣', 'forge_level' => 5,
        'base_power' => 5, 'power_per_level' => 5, 'is_equipped' => false]);
    $before = (array) DB::table('player_nameless_equipments')->find($body);
    $beforeCharacter = $character->fresh()->getAttributes();
    $beforeCount = DB::table('migrations')->count();
    $checks[] = $currentCheck;

    $currentCheck = 'five_migrations_off_preserve_existing_assets';
    app(NamelessPreparationService::class)->applyMigrations();
    requireCheck(DB::table('migrations')->count() === $beforeCount + 5, 'Unexpected migrations applied.');
    $after = (array) DB::table('player_nameless_equipments')->find($body);
    requireCheck(array_intersect_key($after, $before) === $before, 'Existing body changed.');
    requireCheck($character->fresh()->getAttributes() === $beforeCharacter, 'Existing character changed.');
    requireCheck(! app(NamelessWorkshopService::class)->ready(), 'OFF gate opened.');
    app(NamelessPreparationService::class)->applyMigrations();
    requireCheck(DB::table('migrations')->count() === $beforeCount + 5, 'Repeat migration changed history.');
    foreach (['player_relics', 'nameless_workshop_operations', 'nameless_equipment_discoveries', 'nameless_ruin_progress'] as $table) {
        $engine = DB::selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
        requireCheck($engine?->engine === 'InnoDB', 'Fixture table is not InnoDB.');
    }
    $checks[] = $currentCheck;

    $currentCheck = 'concurrent_off_town_registration';
    $townResults = race('town', [[], []]);
    requireCheck($townResults[0]['success'] && $townResults[1]['success']
        && $townResults[0]['result'] === $townResults[1]['result'], 'Concurrent town registration failed.');
    requireCheck(app(NamelessPreparationService::class)->status()['town_count'] === 1
        && app(NamelessTownService::class)->availableTown() === null, 'Town duplicate or exposed while OFF.');
    $character->update(['current_city_id' => $townResults[0]['result']['town_id']]);
    $checks[] = $currentCheck;

    config(['nameless_relics.enabled' => true]);
    foreach (['same_uuid', 'competing_uuid'] as $scenario) {
        $currentCheck = 'concurrent_growth_'.$scenario;
        $relics = collect(range(1, 3))->map(fn () => PlayerRelic::create([
            'character_id' => $character->id, 'effect_key' => 'stat_str', 'rank' => 1]));
        $preview = app(NamelessRelicGrowthService::class)->preview($character, $relics[0]->id, [$relics[1]->id, $relics[2]->id]);
        $payload = ['character' => $character->id, 'target' => $relics[0]->id,
            'sources' => [$relics[1]->id, $relics[2]->id], 'hash' => $preview['confirmation_hash'], 'uuid' => (string) Str::uuid()];
        $other = $payload;
        if ($scenario === 'competing_uuid') { $other['uuid'] = (string) Str::uuid(); }
        $beforeOperations = DB::table('nameless_workshop_operations')->count();
        $results = race('grow', [$payload, $other]);
        requireCheck(count(array_filter($results, fn ($r) => $r['success'])) === ($scenario === 'same_uuid' ? 2 : 1), 'Unexpected concurrent growth outcomes.');
        requireCheck($relics[0]->fresh()->rank === 2 && $relics[0]->fresh()->growth_progress === 0
            && $relics[1]->fresh() === null && $relics[2]->fresh() === null, 'Growth consumed twice or left partial state.');
        requireCheck(DB::table('nameless_workshop_operations')->count() === $beforeOperations + 1, 'Operation ledger count changed.');
        $checks[] = $currentCheck;
    }

    $currentCheck = 'retry_ddl_preserves_attached_relics';
    $attached = PlayerRelic::create(['character_id' => $character->id, 'effect_key' => 'special_opener',
        'rank' => 9, 'nameless_equipment_id' => $body, 'slot_number' => 1]);
    $storedRelics = DB::table('player_relics')->orderBy('id')->get()->toJson();
    config(['nameless_relics.enabled' => false]);
    foreach (NamelessPreparationService::MIGRATIONS as $name) {
        (require database_path('migrations/'.$name.'.php'))->up();
    }
    requireCheck(DB::table('player_relics')->orderBy('id')->get()->toJson() === $storedRelics
        && app(NamelessPreparationService::class)->status()['ready'], 'DDL retry changed relics or constraints.');
    $checks[] = $currentCheck;

    $currentCheck = 'socket_unique_foreign_key_and_destructive_rollback_guard';
    try {
        PlayerRelic::create(['character_id' => $character->id, 'effect_key' => 'stat_def',
            'rank' => 1, 'nameless_equipment_id' => $body, 'slot_number' => 1]);
        throw new LogicException('Duplicate socket accepted.');
    } catch (Illuminate\Database\QueryException $exception) {
        requireCheck(($exception->errorInfo[1] ?? null) === 1062, 'Unexpected unique failure.');
    }
    try {
        PlayerRelic::create(['character_id' => $character->id, 'effect_key' => 'stat_def',
            'rank' => 1, 'character_item_id' => 999999999, 'slot_number' => 1]);
        throw new LogicException('Orphan socket accepted.');
    } catch (Illuminate\Database\QueryException $exception) {
        requireCheck(($exception->errorInfo[1] ?? null) === 1452, 'Unexpected foreign key failure.');
    }
    try {
        (require database_path('migrations/'.NamelessPreparationService::MIGRATIONS[0].'.php'))->down();
        throw new LogicException('Destructive rollback accepted.');
    } catch (RuntimeException $exception) {
        requireCheck(Schema::hasTable('player_relics') && $attached->fresh()->nameless_equipment_id === $body,
            'Rollback guard failed to preserve assets.');
    }
    $checks[] = $currentCheck;

    config(['nameless_relics.enabled' => true]);
    $currentCheck = 'transaction_rollback_and_off_return';
    $beforeMoney = $character->fresh()->money;
    $uuid = (string) Str::uuid();
    try {
        app(NamelessWorkshopService::class)->operation($character, $uuid, 'fixture-rollback', [], function ($locked) {
            $locked->update(['money' => 1]);
            throw new RuntimeException('fixture rollback');
        });
        throw new LogicException('Rollback fixture unexpectedly succeeded.');
    } catch (RuntimeException $exception) {
        requireCheck($exception->getMessage() === 'fixture rollback', 'Unexpected rollback rejection.');
    }
    requireCheck($character->fresh()->money === $beforeMoney
        && ! DB::table('nameless_workshop_operations')->where('request_uuid', $uuid)->exists(), 'Rollback retained partial writes.');
    config(['nameless_relics.enabled' => false]);
    requireCheck(! app(NamelessWorkshopService::class)->ready() && app(NamelessTownService::class)->availableTown() === null,
        'Final OFF state failed.');
    $checks[] = $currentCheck;
    fwrite(STDOUT, json_encode(['pass' => true, 'server_version' => $version, 'checks' => $checks,
        'feature_enabled' => false, 'database' => $database], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDOUT, json_encode(['pass' => false, 'checks' => $checks, 'failed_check' => $currentCheck,
        'error_class' => $exception::class, 'error_location' => basename($exception->getFile()).':'.$exception->getLine(),
        'error' => $exception::class === RuntimeException::class ? $exception->getMessage() : 'Inspect named check; no credentials/bindings emitted.',
        'database_error_code' => $exception->errorInfo[1] ?? null], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL);
    exit(1);
}
