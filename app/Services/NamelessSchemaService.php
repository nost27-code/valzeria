<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read-only, shared by the runtime gate and the OFF preparation command. */
final class NamelessSchemaService
{
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

    public function problems(): array
    {
        $problems = [];
        if (! Schema::hasTable('migrations')) {
            $problems[] = 'migrations:table_missing';
        } else {
            $applied = DB::table('migrations')->pluck('migration')->all();
            foreach (array_diff(NamelessPreparationService::MIGRATIONS, $applied) as $migration) {
                $problems[] = 'migrations:pending:'.$migration;
            }
        }
        $maria = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
        foreach (self::COLUMNS as $table => $required) {
            if (! Schema::hasTable($table)) {
                $problems[] = $table.':table_missing';
                continue;
            }
            $columns = collect(Schema::getColumns($table))->keyBy('name');
            foreach ($required as $name => [$type, $nullable]) {
                $column = $columns->get($name);
                if (! $column) {
                    $problems[] = $table.':column_missing:'.$name;
                    continue;
                }
                if (! $this->typeMatches($table, $name, $column, $type, $maria)) {
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
            $indexes = Schema::getIndexes($table);
            if (! collect($indexes)->contains(fn ($index) => $index['primary'] && $index['columns'] === ['id'])) {
                $problems[] = $table.':primary:id';
            }
            if ($maria && strtolower((string) DB::scalar('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table])) !== 'innodb') {
                $problems[] = $table.':engine:expected=InnoDB';
            }
        }
        return [...$problems, ...$this->constraintProblems()];
    }

    private function typeMatches(string $table, string $name, array $column, string $expected, bool $maria): bool
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
                $checks = DB::select('SELECT cc.CHECK_CLAUSE AS clause FROM information_schema.CHECK_CONSTRAINTS cc JOIN information_schema.TABLE_CONSTRAINTS tc ON tc.CONSTRAINT_SCHEMA=cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME=cc.CONSTRAINT_NAME AND tc.TABLE_NAME=cc.TABLE_NAME WHERE tc.TABLE_SCHEMA=DATABASE() AND tc.TABLE_NAME=?', [$table]);
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

    private function constraintProblems(): array
    {
        $problems = [];
        $groups = [
            'player_relics' => [['nameless_equipment_id', 'slot_number'], ['character_item_id', 'slot_number']],
            'nameless_workshop_operations' => [['character_id', 'request_uuid']],
            'nameless_equipment_discoveries' => [['character_id', 'equipment_type']],
            'nameless_ruin_progress' => [['character_id', 'zone_key']],
        ];
        foreach ($groups as $table => $required) {
            if (! Schema::hasTable($table)) { continue; }
            $indexes = Schema::getIndexes($table);
            foreach ($required as $columns) {
                if (! collect($indexes)->contains(fn ($index) => $index['unique'] && $index['columns'] === $columns)) {
                    $problems[] = $table.':unique:'.implode(',', $columns);
                }
            }
        }
        if (Schema::hasTable('player_nameless_equipments') && collect(Schema::getIndexes('player_nameless_equipments'))->contains(fn ($index) => $index['unique'] && $index['columns'] === ['character_id', 'kind'])) {
            $problems[] = 'player_nameless_equipments:obsolete_owner_kind_unique';
        }
        foreach ([
            'player_nameless_equipments' => ['character_id' => ['characters', 'cascade']],
            'player_relics' => ['character_id' => ['characters', 'cascade'], 'nameless_equipment_id' => ['player_nameless_equipments', 'set null'], 'character_item_id' => ['character_items', 'set null']],
            'nameless_workshop_operations' => ['character_id' => ['characters', 'cascade']],
            'nameless_equipment_discoveries' => ['character_id' => ['characters', 'cascade']],
            'nameless_ruin_progress' => ['character_id' => ['characters', 'cascade']],
        ] as $table => $references) {
            if (! Schema::hasTable($table)) { continue; }
            $foreignKeys = Schema::getForeignKeys($table);
            foreach ($references as $column => [$target, $delete]) {
                if (! collect($foreignKeys)->contains(fn ($key) => $key['columns'] === [$column] && $key['foreign_table'] === $target && $key['foreign_columns'] === ['id'] && strtolower($key['on_delete']) === $delete)) {
                    $problems[] = $table.':foreign:'.$column.':expected='.$target.'.id/'.$delete;
                }
            }
        }
        return $problems;
    }
}
