<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** 対象5本だけのOFF準備。全migration実行や設定ONは行わない。 */
class NamelessPreparationService
{
    public const MIGRATIONS = [
        '2026_10_03_010000_create_nameless_relic_prototype_tables',
        '2026_10_03_030000_enable_nameless_equipment_collection',
        '2026_10_04_060000_add_growth_progress_to_player_relics',
        '2026_10_05_070000_extend_nameless_equipment_kind_for_accessories',
        '2026_10_05_080000_add_ordinary_equipment_relic_sockets',
    ];

    public function status(): array
    {
        $applied = Schema::hasTable('migrations') ? DB::table('migrations')->pluck('migration')->all() : [];
        $pending = array_values(array_diff(self::MIGRATIONS, $applied));
        $schema = app(NamelessWorkshopService::class)->schemaReady();
        $problems = $schema ? $this->constraintProblems() : ['required_schema_missing'];
        return [
            'enabled' => app(NamelessWorkshopService::class)->enabled(),
            'pending_migrations' => $pending, 'schema_ready' => $schema,
            'constraint_problems' => $problems,
            'ready' => $pending === [] && $schema && $problems === [],
            'town_count' => Schema::hasTable('cities')
                ? DB::table('cities')->where('unlock_condition_type', NamelessTownService::MARKER)->count() : 0,
        ];
    }

    public function applyMigrations(): void
    {
        $this->assertOff();
        if (! Schema::hasTable('player_nameless_equipments') || ! Schema::hasTable('characters')
            || ! Schema::hasTable('character_items') || ! Schema::hasTable('migrations')) {
            throw new RuntimeException('既存の武具・Character・通常装備schemaが必要です。');
        }
        $connection = DB::connection();
        $maria = in_array($connection->getDriverName(), ['mysql', 'mariadb'], true);
        $lock = 'valzeria.nameless.prepare';
        if ($maria && (int) $connection->selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$lock])->acquired !== 1) {
            throw new RuntimeException('遺物DB移行が実行中です。');
        }
        try {
            $paths = array_map(fn ($name) => 'database/migrations/'.$name.'.php', self::MIGRATIONS);
            $exit = Artisan::call('migrate', ['--path' => $paths, '--force' => true]);
            if ($exit !== 0 || ! $this->status()['ready']) {
                throw new RuntimeException('対象DB移行の確認が完了していません。nameless:prepareで状態を確認してください。');
            }
        } finally {
            if ($maria) {
                $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lock]);
            }
        }
        $this->assertOff();
    }

    public function assertOff(): void
    {
        if (app(NamelessWorkshopService::class)->enabled()) {
            throw new RuntimeException('準備操作はNAMELESS_RELICS_ENABLED=falseで実行してください。');
        }
    }

    private function constraintProblems(): array
    {
        $problems = [];
        $required = [
            'player_relics' => [
                ['nameless_equipment_id', 'slot_number'], ['character_item_id', 'slot_number'],
            ],
            'nameless_workshop_operations' => [['character_id', 'request_uuid']],
            'nameless_equipment_discoveries' => [['character_id', 'equipment_type']],
            'nameless_ruin_progress' => [['character_id', 'zone_key']],
        ];
        foreach ($required as $table => $groups) {
            $indexes = Schema::getIndexes($table);
            foreach ($groups as $columns) {
                if (! collect($indexes)->contains(fn ($index) => $index['unique'] && $index['columns'] === $columns)) {
                    $problems[] = $table.':unique:'.implode(',', $columns);
                }
            }
        }
        if (collect(Schema::getIndexes('player_nameless_equipments'))->contains(
            fn ($index) => $index['unique'] && $index['columns'] === ['character_id', 'kind'])) {
            $problems[] = 'player_nameless_equipments:obsolete_owner_kind_unique';
        }
        foreach ([
            'player_relics' => ['character_id' => 'characters', 'nameless_equipment_id' => 'player_nameless_equipments', 'character_item_id' => 'character_items'],
            'nameless_workshop_operations' => ['character_id' => 'characters'],
            'nameless_equipment_discoveries' => ['character_id' => 'characters'],
            'nameless_ruin_progress' => ['character_id' => 'characters'],
        ] as $table => $references) {
            $foreignKeys = Schema::getForeignKeys($table);
            foreach ($references as $column => $target) {
                if (! collect($foreignKeys)->contains(fn ($key) => $key['columns'] === [$column]
                    && $key['foreign_table'] === $target && $key['foreign_columns'] === ['id'])) {
                    $problems[] = $table.':foreign:'.$column;
                }
            }
        }
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $kind = DB::selectOne('SELECT COLUMN_TYPE AS kind_type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', ['player_nameless_equipments', 'kind']);
            if (! $kind || $kind->kind_type !== "enum('weapon','armor','accessory')") {
                $problems[] = 'player_nameless_equipments:accessory_enum_missing';
            }
        }
        return $problems;
    }
}
