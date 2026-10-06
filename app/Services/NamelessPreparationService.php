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
        $problems = app(NamelessSchemaService::class)->problems();
        $schema = $problems === [];
        return [
            'enabled' => app(NamelessWorkshopService::class)->enabled(),
            'pending_migrations' => $pending, 'schema_ready' => $schema,
            'schema_problems' => $problems, 'constraint_problems' => $problems,
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
            $status = $this->status();
            if ($exit !== 0 || ! $status['ready']) {
                throw new RuntimeException('対象DB移行の確認が完了していません: '.implode(', ', $status['schema_problems']).'。nameless:prepare --jsonで状態を確認してください。');
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

}
