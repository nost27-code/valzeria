<?php

namespace App\Services;

use Illuminate\Support\Facades\Schema;

class SchemaStateService
{
    /** @var array<string, bool> */
    private array $tables = [];

    /** @var array<string, array<int, string>> */
    private array $columnListings = [];

    public function hasTable(string $table): bool
    {
        return $this->tables[$table] ??= Schema::hasTable($table);
    }

    public function hasColumn(string $table, string $column): bool
    {
        if (! $this->hasTable($table)) {
            return false;
        }

        $columns = $this->columnListings[$table] ??= Schema::getColumnListing($table);

        return in_array($column, $columns, true);
    }

    /** @param array<int, string> $columns */
    public function hasColumns(string $table, array $columns): bool
    {
        foreach ($columns as $column) {
            if (! $this->hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }
}
