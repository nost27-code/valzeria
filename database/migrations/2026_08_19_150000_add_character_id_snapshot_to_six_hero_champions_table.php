<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX_NAME = 'six_hero_champions_character_id_snapshot_idx';

    private const TRIGGER_NAME = 'six_hero_champions_identity_snapshot_immutable';

    private const POSTGRES_FUNCTION_NAME = 'six_hero_champions_identity_snapshot_immutable_fn';

    public function up(): void
    {
        $hasSnapshotColumn = Schema::hasColumn(
            'six_hero_champions',
            'character_id_snapshot',
        );
        $unrecoverableHeroes = DB::table('six_hero_champions')
            ->where('is_vacant', false)
            ->whereNull('character_id');
        if ($hasSnapshotColumn) {
            $unrecoverableHeroes->whereNull('character_id_snapshot');
        }
        if ($unrecoverableHeroes->exists()) {
            throw new LogicException(
                'A non-vacant Six Heroes Champion has no identity available for snapshot backfill.',
            );
        }

        if (! $hasSnapshotColumn) {
            Schema::table('six_hero_champions', function (Blueprint $table): void {
                $table->unsignedBigInteger('character_id_snapshot')
                    ->nullable()
                    ->after('character_id');
            });
        }

        if (! Schema::hasIndex('six_hero_champions', self::INDEX_NAME)) {
            Schema::table('six_hero_champions', function (Blueprint $table): void {
                $table->index('character_id_snapshot', self::INDEX_NAME);
            });
        }

        DB::table('six_hero_champions')
            ->where('is_vacant', false)
            ->whereNotNull('character_id')
            ->whereNull('character_id_snapshot')
            ->update([
                'character_id_snapshot' => DB::raw('character_id'),
            ]);

        $this->createIdentitySnapshotTrigger();
    }

    public function down(): void
    {
        $this->dropIdentitySnapshotTrigger();

        if (Schema::hasIndex('six_hero_champions', self::INDEX_NAME)) {
            Schema::table('six_hero_champions', function (Blueprint $table): void {
                $table->dropIndex(self::INDEX_NAME);
            });
        }

        if (Schema::hasColumn('six_hero_champions', 'character_id_snapshot')) {
            Schema::table('six_hero_champions', function (Blueprint $table): void {
                $table->dropColumn('character_id_snapshot');
            });
        }
    }

    private function createIdentitySnapshotTrigger(): void
    {
        $this->dropIdentitySnapshotTrigger();

        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER six_hero_champions_identity_snapshot_immutable
                BEFORE UPDATE OF character_id_snapshot ON six_hero_champions
                FOR EACH ROW
                WHEN OLD.character_id_snapshot IS NOT NEW.character_id_snapshot
                BEGIN
                    SELECT RAISE(ABORT, 'Six Heroes Champion identity snapshots are immutable.');
                END
                SQL);

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER `six_hero_champions_identity_snapshot_immutable`
                BEFORE UPDATE ON `six_hero_champions`
                FOR EACH ROW
                BEGIN
                    IF NOT (NEW.`character_id_snapshot` <=> OLD.`character_id_snapshot`) THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'Six Heroes Champion identity snapshots are immutable.';
                    END IF;
                END
                SQL);

            return;
        }

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION six_hero_champions_identity_snapshot_immutable_fn()
                RETURNS trigger AS $$
                BEGIN
                    IF NEW.character_id_snapshot IS DISTINCT FROM OLD.character_id_snapshot THEN
                        RAISE EXCEPTION 'Six Heroes Champion identity snapshots are immutable.';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER six_hero_champions_identity_snapshot_immutable
                BEFORE UPDATE ON six_hero_champions
                FOR EACH ROW
                EXECUTE FUNCTION six_hero_champions_identity_snapshot_immutable_fn()
                SQL);

            return;
        }

        throw new LogicException(
            "Unsupported database driver for immutable Champion snapshots: {$driver}.",
        );
    }

    private function dropIdentitySnapshotTrigger(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::unprepared(
                'DROP TRIGGER IF EXISTS six_hero_champions_identity_snapshot_immutable '
                .'ON six_hero_champions',
            );
            DB::unprepared(
                'DROP FUNCTION IF EXISTS six_hero_champions_identity_snapshot_immutable_fn()',
            );

            return;
        }

        if (in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.self::TRIGGER_NAME);
        }
    }
};
