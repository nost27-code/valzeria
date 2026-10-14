<?php

namespace App\Services;

/** Decorates the current Schema facade during one operation, without caching game data. */
final class BattleSchemaMetadata
{
    private array $tables = [];

    private array $columns = [];

    public function __construct(private readonly object $schema) {}

    public function hasTable(string $table): bool
    {
        return $this->tables[$table] ??= $this->schema->hasTable($table);
    }

    public function getColumnListing(string $table): array
    {
        return $this->columns[$table] ??= $this->schema->getColumnListing($table);
    }

    public function hasColumn(string $table, string $column): bool
    {
        return in_array(strtolower($column), array_map(strtolower(...), $this->getColumnListing($table)), true);
    }

    public function hasColumns(string $table, array $columns): bool
    {
        foreach ($columns as $column) {
            if (! $this->hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }

    public function invalidate(): void
    {
        $this->tables = $this->columns = [];
    }

    public function __call(string $method, array $arguments): mixed
    {
        // Unknown methods (including DDL) remain delegated and cannot leave stale metadata.
        $this->tables = $this->columns = [];
        try {
            return $this->schema->{$method}(...$arguments);
        } finally {
            $this->tables = $this->columns = [];
        }
    }
}
