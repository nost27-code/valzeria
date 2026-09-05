<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;

// Both parent and workers must pass this before Laravel (or any DB connection) boots.
if (! in_array('--confirm-isolated-database', $argv, true)) {
    fwrite(STDERR, "Refusing to run without --confirm-isolated-database.\n");
    exit(2);
}
// Require explicit process environment; never fall back to the application's local/production .env.
if (getenv('APP_ENV') !== 'testing'
    || preg_match('/\Avalzeria_nation_raid_phase4_[a-z0-9_]+\z/', (string) getenv('DB_DATABASE')) !== 1) {
    fwrite(STDERR, "Refusing to boot without an explicit testing environment and disposable Phase 4 database.\n");
    exit(2);
}

require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/support/NationRaidPhase4MariaDbSafety.php';
require __DIR__.'/support/NationRaidPhase4MariaDbHarness.php';

$harness = null;
try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $harness = new NationRaidPhase4MariaDbHarness;
    $harness->preflight();
    $command = $argv[1] ?? 'all';
    NationRaidPhase4MariaDbSafety::require(in_array($command, ['all', 'worker'], true), 'Expected all or worker.');
    $result = $command === 'worker'
        ? $harness->worker((string) ($argv[2] ?? ''))
        : $harness->run();
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL);
} catch (Throwable $exception) {
    // Do not print connection credentials, query bindings, player snapshots or stack traces.
    fwrite(STDOUT, json_encode([
        'pass' => false, 'completed_checks' => $harness?->checks ?? [],
        'failed_check' => $harness?->currentCheck,
        'error_class' => $exception::class,
        'error_location' => basename($exception->getFile()).':'.$exception->getLine(),
        'error' => $exception::class === RuntimeException::class ? $exception->getMessage() : 'Verification failed; inspect the named check.',
        'database_error_code' => $exception->errorInfo[1] ?? null,
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL);
    exit(1);
}
