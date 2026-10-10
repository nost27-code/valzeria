<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read-only, shared by the runtime gate and the OFF preparation command. */
final class NamelessSchemaService
{
    private bool $snapshotActive = false;

    private ?array $snapshot = null;

    /** Share one inspection inside an explicit synchronous operation; only metadata is retained. */
    public function withSnapshot(callable $operation): mixed
    {
        if ($this->snapshotActive) {
            return $operation();
        }

        $this->snapshotActive = true;
        try {
            return $operation();
        } finally {
            $this->snapshot = null;
            $this->snapshotActive = false;
        }
    }

    /** Reuse schema metadata during one battle, never owned assets or config.
     * Prime before gameplay locks; the next operation always inspects again.
     */
    public function withBattleSnapshot(callable $operation): mixed
    {
        return $this->withSnapshot(function () use ($operation) {
            if ((bool) config('nameless_relics.enabled', false)) {
                $this->problems();
            }
            return $operation();
        });
    }

    /** type, nullable, default (where writes rely on one). Integer types are minimum widths. */
    public const COLUMNS = [
        'player_nameless_equipments' => [
            'id' => ['id', false], 'character_id' => ['bigint', false], 'kind' => ['kind', false],
            'custom_name' => ['string:32', true], 'equipment_type' => ['string:32', false],
            'forge_level' => ['tinyint', false, '0'], 'base_power' => ['smallint', false, '5'],
            'power_per_level' => ['smallint', false, '5'], 'is_equipped' => ['bool', false, '0'],
            'growth_exp' => ['bigint', false, '0'], 'revision' => ['int', false, '0'],
            'acquisition_source' => ['string:16', false, 'starter'], 'is_locked' => ['bool', false, '0'],
            'created_at' => ['timestamp', true], 'updated_at' => ['timestamp', true],
        ],
        'player_relics' => [
            'id' => ['id', false], 'character_id' => ['bigint', false], 'effect_key' => ['string:64', false],
            'rank' => ['tinyint', false], 'is_locked' => ['bool', false, '0'],
            'nameless_equipment_id' => ['bigint', true], 'character_item_id' => ['bigint', true],
            'slot_number' => ['tinyint', true], 'growth_progress' => ['tinyint', false, '0'],
            'created_at' => ['timestamp', true], 'updated_at' => ['timestamp', true],
        ],
        'nameless_workshop_operations' => [
            'id' => ['id', false], 'character_id' => ['bigint', false], 'request_uuid' => ['string:36', false],
            'action' => ['string:32', false], 'payload_hash' => ['string:64', false], 'result' => ['json', false],
            'created_at' => ['timestamp', true], 'updated_at' => ['timestamp', true],
        ],
        'nameless_equipment_discoveries' => [
            'id' => ['id', false], 'character_id' => ['bigint', false], 'kind' => ['string:16', false],
            'equipment_type' => ['string:32', false], 'created_at' => ['timestamp', true], 'updated_at' => ['timestamp', true],
        ],
        'nameless_ruin_progress' => [
            'id' => ['id', false], 'character_id' => ['bigint', false], 'zone_key' => ['string:32', false],
            'unlocked_depth' => ['int', false, '1'], 'created_at' => ['timestamp', true], 'updated_at' => ['timestamp', true],
        ],
    ];

    /** Existing FK targets must physically exist, not merely be named by a retained FK. */
    private const REFERENCES = ['characters' => ['id' => ['id', false]], 'character_items' => ['id' => ['id', false]]];

    public function problems(): array
    {
        return $this->snapshotActive
            ? ($this->snapshot ??= $this->inspectProblems())
            : $this->inspectProblems();
    }

    private function inspectProblems(): array
    {
        $problems = [];
        $maria = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
        // Outside an explicit operation, every inspection stays fresh.
        $metadata = $this->metadata($maria);
        if (! isset($metadata['migrations'])) {
            $problems[] = 'migrations:table_missing';
        } elseif (! isset($metadata['migrations']['columns']['migration'])) {
            $problems[] = 'migrations:column_missing:migration';
        } else {
            $applied = DB::table('migrations')->useWritePdo()->whereIn('migration', NamelessPreparationService::MIGRATIONS)->pluck('migration')->all();
            foreach (array_diff(NamelessPreparationService::MIGRATIONS, $applied) as $migration) {
                $problems[] = 'migrations:pending:'.$migration;
            }
        }
        foreach ([...self::COLUMNS, ...self::REFERENCES] as $table => $required) {
            if (! isset($metadata[$table])) {
                $problems[] = $table.':table_missing';
                continue;
            }
            $columns = $metadata[$table]['columns'];
            foreach ($required as $name => [$type, $nullable]) {
                $column = $columns[$name] ?? null;
                if (! $column) {
                    $problems[] = $table.':column_missing:'.$name;
                    continue;
                }
                if (! $this->typeMatches($table, $name, $column, $type, $maria, $metadata[$table]['checks'])) {
                    $problems[] = $table.':type:'.$name.':expected='.$type.':actual='.$column['type'];
                }
                if ($type !== 'id' && $column['nullable'] !== $nullable) {
                    $problems[] = $table.':nullable:'.$name.':expected='.(int) $nullable;
                }
                if (isset($required[$name][2]) && trim((string) $column['default'], "'\"()") !== $required[$name][2]) {
                    $problems[] = $table.':default:'.$name.':expected='.$required[$name][2];
                }
                if ($column['generation'] !== null) {
                    $problems[] = $table.':generated_column:'.$name;
                }
            }
            $indexes = $metadata[$table]['indexes'];
            if (! collect($indexes)->contains(fn ($index) => $index['primary'] && $index['columns'] === ['id'])) {
                $problems[] = $table.':primary:id';
            }
            if ($maria && strtolower((string) $metadata[$table]['engine']) !== 'innodb') {
                $problems[] = $table.':engine:expected=InnoDB';
            }
        }
        return [...$problems, ...$this->constraintProblems($metadata)];
    }

    /** Batch MariaDB metadata; keep Laravel's column/index/FK normalization and write PDO. */
    private function metadata(bool $maria): array
    {
        $tables = ['migrations', ...array_keys(self::COLUMNS), ...array_keys(self::REFERENCES)];
        $metadata = [];
        if (! $maria) {
            foreach ($tables as $table) {
                if (! Schema::hasTable($table)) { continue; }
                $metadata[$table] = [
                    'columns' => array_column(Schema::getColumns($table), null, 'name'),
                    'indexes' => $table === 'migrations' ? [] : Schema::getIndexes($table),
                    'foreign_keys' => isset(self::COLUMNS[$table]) ? Schema::getForeignKeys($table) : [],
                    'engine' => null, 'checks' => [],
                ];
            }
            return $metadata;
        }

        $connection = DB::connection();
        $processor = $connection->getPostProcessor();
        $physical = array_map(fn ($table) => $connection->getTablePrefix().$table, $tables);
        $logical = array_combine($physical, $tables);
        $in = implode(', ', array_fill(0, count($physical), '?'));
        // Literal schema predicates let MariaDB prune unrelated hosted schemas before reading catalogs.
        $databaseName = (string) $connection->selectFromWriteConnection('SELECT DATABASE() AS name')[0]->name;
        $databaseLiteral = $connection->getPdo()->quote($databaseName);
        $select = fn (string $sql) => $connection->selectFromWriteConnection(str_replace('DATABASE()', $databaseLiteral, $sql), $physical);
        foreach ($select("SELECT TABLE_NAME AS table_name, ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ($in) AND TABLE_TYPE IN ('BASE TABLE', 'SYSTEM VERSIONED')") as $row) {
            $metadata[$logical[$row->table_name]] = ['columns' => [], 'indexes' => [], 'foreign_keys' => [], 'checks' => [], 'engine' => $row->engine];
        }
        $columns = $select("SELECT TABLE_NAME AS table_name, COLUMN_NAME AS name, DATA_TYPE AS type_name, COLUMN_TYPE AS type, COLLATION_NAME AS collation, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS `default`, COLUMN_COMMENT AS comment, GENERATION_EXPRESSION AS expression, EXTRA AS extra FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ($in) ORDER BY TABLE_NAME, ORDINAL_POSITION");
        $indexes = $select("SELECT TABLE_NAME AS table_name, INDEX_NAME AS name, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS `columns`, INDEX_TYPE AS type, NOT NON_UNIQUE AS `unique` FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ($in) GROUP BY TABLE_NAME, INDEX_NAME, INDEX_TYPE, NON_UNIQUE");
        $keys = $select("SELECT TABLE_NAME AS table_name, CONSTRAINT_NAME AS name, GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION) AS `columns`, REFERENCED_TABLE_SCHEMA AS foreign_schema, REFERENCED_TABLE_NAME AS foreign_table, GROUP_CONCAT(REFERENCED_COLUMN_NAME ORDER BY ORDINAL_POSITION) AS foreign_columns FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ($in) AND REFERENCED_TABLE_NAME IS NOT NULL GROUP BY TABLE_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_SCHEMA, REFERENCED_TABLE_NAME");
        // Joining these virtual catalogs can materialize unrelated databases on shared hosts.
        $rules = collect($select("SELECT TABLE_NAME AS table_name, CONSTRAINT_NAME AS name, UPDATE_RULE AS on_update, DELETE_RULE AS on_delete FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME IN ($in)"))->keyBy(fn ($row) => $row->table_name.'.'.$row->name);
        foreach ($keys as $key) {
            $rule = $rules->get($key->table_name.'.'.$key->name);
            $key->on_update = $rule?->on_update ?? '';
            $key->on_delete = $rule?->on_delete ?? '';
        }
        // MariaDB represents JSON as LONGTEXT + CHECK. Native JSON needs no CHECK query.
        $needsJsonChecks = collect($columns)->contains(fn ($column) => $column->type_name === 'longtext'
            && (self::COLUMNS[$logical[$column->table_name]][$column->name][0] ?? null) === 'json');
        $checks = $needsJsonChecks ? $select("SELECT TABLE_NAME AS table_name, CHECK_CLAUSE AS clause FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME IN ($in)") : [];
        foreach (['columns' => $columns, 'indexes' => $indexes, 'foreign_keys' => $keys, 'checks' => $checks] as $kind => $rows) {
            foreach (collect($rows)->groupBy('table_name') as $table => $group) {
                $name = $logical[$table];
                if (! isset($metadata[$name])) { continue; }
                $metadata[$name][$kind] = match ($kind) {
                    'columns' => array_column($processor->processColumns($group->all()), null, 'name'),
                    'indexes' => $processor->processIndexes($group->all()),
                    'foreign_keys' => $processor->processForeignKeys($group->all()),
                    'checks' => $group->all(),
                };
            }
        }
        return $metadata;
    }

    private function typeMatches(string $table, string $name, array $column, string $expected, bool $maria, array $checks): bool
    {
        $type = strtolower($column['type_name']);
        $raw = strtolower($column['type']);
        if ($expected === 'kind') {
            if ($maria) {
                return $raw === "enum('weapon','armor','accessory')";
            }
            $sql = (string) DB::scalar("SELECT sql FROM sqlite_master WHERE type='table' AND name=?", [$table]);
            return preg_match('/check\s*\(\s*["`]?kind["`]?\s+in\s*\(\s*\x27weapon\x27\s*,\s*\x27armor\x27\s*,\s*\x27accessory\x27\s*\)\s*\)/i', $sql) === 1;
        }
        if ($expected === 'json') {
            if ($type === 'json' || (! $maria && $type === 'text')) {
                return true;
            }
            if ($maria && $type === 'longtext') {
                return collect($checks)->contains(fn ($check) => preg_match('/\A\s*(?:\(\s*)*json_valid\s*\(\s*`?'.preg_quote($name, '/').'`?\s*\)(?:\s*\))*\s*\z/i', $check->clause) === 1);
            }
            return false;
        }
        if (str_starts_with($expected, 'string:')) {
            if (! $maria) { return in_array($type, ['varchar', 'char'], true); }
            return in_array($type, ['varchar', 'char'], true) && preg_match('/\((\d+)\)/', $raw, $match)
                && (int) $match[1] >= (int) substr($expected, 7);
        }
        if ($expected === 'timestamp') {
            return in_array($type, ['datetime', 'timestamp'], true);
        }
        if ($expected === 'bool') {
            return $maria ? $type === 'tinyint' : $type === 'tinyint' || $type === 'boolean';
        }
        $widths = ['tinyint' => 1, 'smallint' => 2, 'mediumint' => 3, 'int' => 4, 'integer' => 4, 'bigint' => 5];
        $minimum = $expected === 'id' ? 'bigint' : $expected;
        if (! $maria) {
            return in_array($type, ['integer', 'int'], true) && ($expected !== 'id' || $column['auto_increment']);
        }
        return ($widths[$type] ?? 0) >= ($widths[$minimum] ?? PHP_INT_MAX)
            && str_contains($raw, 'unsigned') && ($expected !== 'id' || $column['auto_increment']);
    }

    private function constraintProblems(array $metadata): array
    {
        $problems = [];
        $groups = [
            'player_relics' => [['nameless_equipment_id', 'slot_number'], ['character_item_id', 'slot_number']],
            'nameless_workshop_operations' => [['character_id', 'request_uuid']],
            'nameless_equipment_discoveries' => [['character_id', 'equipment_type']],
            'nameless_ruin_progress' => [['character_id', 'zone_key']],
        ];
        foreach ($groups as $table => $required) {
            if (! isset($metadata[$table])) { continue; }
            $indexes = $metadata[$table]['indexes'];
            foreach ($required as $columns) {
                if (! collect($indexes)->contains(fn ($index) => $index['unique'] && $index['columns'] === $columns)) {
                    $problems[] = $table.':unique:'.implode(',', $columns);
                }
            }
        }
        if (isset($metadata['player_nameless_equipments']) && collect($metadata['player_nameless_equipments']['indexes'])->contains(fn ($index) => $index['unique'] && $index['columns'] === ['character_id', 'kind'])) {
            $problems[] = 'player_nameless_equipments:obsolete_owner_kind_unique';
        }
        foreach ([
            'player_nameless_equipments' => ['character_id' => ['characters', 'cascade']],
            'player_relics' => ['character_id' => ['characters', 'cascade'], 'nameless_equipment_id' => ['player_nameless_equipments', 'set null'], 'character_item_id' => ['character_items', 'set null']],
            'nameless_workshop_operations' => ['character_id' => ['characters', 'cascade']],
            'nameless_equipment_discoveries' => ['character_id' => ['characters', 'cascade']],
            'nameless_ruin_progress' => ['character_id' => ['characters', 'cascade']],
        ] as $table => $references) {
            if (! isset($metadata[$table])) { continue; }
            $foreignKeys = $metadata[$table]['foreign_keys'];
            foreach ($references as $column => [$target, $delete]) {
                if (! collect($foreignKeys)->contains(fn ($key) => $key['columns'] === [$column] && $key['foreign_table'] === $target && $key['foreign_columns'] === ['id'] && strtolower($key['on_delete']) === $delete)) {
                    $problems[] = $table.':foreign:'.$column.':expected='.$target.'.id/'.$delete;
                }
            }
        }
        return $problems;
    }
}
