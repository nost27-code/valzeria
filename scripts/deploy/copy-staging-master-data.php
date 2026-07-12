#!/usr/bin/env php
<?php

declare(strict_types=1);

use Dotenv\Dotenv;
if ($argc < 5) {
    fwrite(STDERR, "Usage: {$argv[0]} <staging-app> <production-env> <staging-env> <table>...\n");
    exit(64);
}

[$script, $stagingApp, $productionEnv, $stagingEnv] = array_slice($argv, 0, 4);
$tables = array_slice($argv, 4);

require $stagingApp . '/vendor/autoload.php';

/** @return array<string, string> */
function environment(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException("Environment file is missing: {$path}");
    }

    return Dotenv::parse((string) file_get_contents($path));
}

/** @param array<string, string> $settings */
function connection(array $settings): \PDO
{
    $database = $settings['DB_DATABASE'] ?? '';
    $username = $settings['DB_USERNAME'] ?? '';
    if ($database === '' || $username === '') {
        throw new \RuntimeException('Database settings are incomplete.');
    }

    $host = $settings['DB_HOST'] ?? '127.0.0.1';
    $port = $settings['DB_PORT'] ?? '3306';

    return new \PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $username,
        $settings['DB_PASSWORD'] ?? '',
        [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ],
    );
}

/** @return array<string, array{Field:string,Null:string,Default:mixed,Extra:string}> */
function columns(\PDO $connection, string $table): array
{
    $rows = $connection->query("SHOW COLUMNS FROM `{$table}`")->fetchAll();
    if ($rows === []) {
        throw new \RuntimeException("Required master table is missing: {$table}");
    }

    return array_column($rows, null, 'Field');
}

function quoteIdentifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

$source = connection(environment($productionEnv));
$target = connection(environment($stagingEnv));

try {
    $target->exec('SET FOREIGN_KEY_CHECKS=0');

    foreach ($tables as $table) {
        $sourceColumns = columns($source, $table);
        $targetColumns = columns($target, $table);

        $copyColumns = array_values(array_filter(
            array_keys($targetColumns),
            static fn (string $column): bool => isset($sourceColumns[$column])
                && !str_contains(strtoupper($targetColumns[$column]['Extra']), 'GENERATED'),
        ));

        if ($copyColumns === []) {
            throw new \RuntimeException("No compatible columns found for master table: {$table}");
        }

        foreach ($targetColumns as $column => $definition) {
            if (in_array($column, $copyColumns, true)
                || str_contains(strtoupper($definition['Extra']), 'GENERATED')
                || $definition['Null'] === 'YES'
                || $definition['Default'] !== null
                || str_contains(strtolower($definition['Extra']), 'auto_increment')) {
                continue;
            }

            throw new \RuntimeException("Staging requires a source value for {$table}.{$column}");
        }

        $quotedColumns = implode(', ', array_map('quoteIdentifier', $copyColumns));
        $target->exec('DELETE FROM ' . quoteIdentifier($table));

        $read = $source->query('SELECT ' . $quotedColumns . ' FROM ' . quoteIdentifier($table));
        $write = $target->prepare(
            'INSERT INTO ' . quoteIdentifier($table) . ' (' . $quotedColumns . ') VALUES ('
            . implode(', ', array_fill(0, count($copyColumns), '?')) . ')',
        );

        $copied = 0;
        while (($row = $read->fetch()) !== false) {
            $write->execute(array_map(static fn (string $column): mixed => $row[$column], $copyColumns));
            $copied++;
        }

        $sourceCount = (int) $source->query('SELECT COUNT(*) FROM ' . quoteIdentifier($table))->fetchColumn();
        $targetCount = (int) $target->query('SELECT COUNT(*) FROM ' . quoteIdentifier($table))->fetchColumn();
        if ($sourceCount !== $targetCount || $copied !== $sourceCount) {
            throw new \RuntimeException("Master count mismatch after sync: {$table} (production={$sourceCount}, staging={$targetCount})");
        }

        $sourceOnly = array_diff(array_keys($sourceColumns), array_keys($targetColumns));
        if ($sourceOnly !== []) {
            fwrite(STDOUT, "Copied {$table}: {$copied} rows (source-only columns skipped: " . implode(', ', $sourceOnly) . ")\n");
        } else {
            fwrite(STDOUT, "Copied {$table}: {$copied} rows\n");
        }
    }
} finally {
    $target->exec('SET FOREIGN_KEY_CHECKS=1');
}

echo "Staging master data synchronized from production.\n";
