<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_DEFAULT = 'green_castle';

    private const DEFAULT_KEY = 'nation_crest_001';

    public function up(): void
    {
        if (! Schema::hasTable('nations') || ! Schema::hasColumn('nations', 'emblem_key')) {
            return;
        }

        $this->setDefault(self::DEFAULT_KEY);

        DB::transaction(function (): void {
            DB::table('nations')->where('emblem_key', 'green_castle')->update(['emblem_key' => 'nation_crest_001']);
            DB::table('nations')->where('emblem_key', 'blue_shield')->update(['emblem_key' => 'nation_crest_002']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('nations') || ! Schema::hasColumn('nations', 'emblem_key')) {
            return;
        }

        $this->setDefault(self::LEGACY_DEFAULT);
    }

    private function setDefault(string $default): void
    {
        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE `nations` MODIFY `emblem_key` VARCHAR(32) NOT NULL DEFAULT '{$default}'");

            return;
        }

        if ($driver === 'sqlite') {
            $this->setSqliteDefault($default);

            return;
        }

        Schema::table('nations', function (Blueprint $table) use ($default): void {
            $table->string('emblem_key', 32)->default($default)->change();
        });
    }

    private function setSqliteDefault(string $default): void
    {
        // SQLite has no ALTER COLUMN DEFAULT. Laravel's change() rebuilds the parent
        // table and can cascade-delete nation children when called inside a test
        // transaction, so update only this column default in the stored schema.
        $table = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'nations'");
        $sql = is_string($table?->sql ?? null) ? $table->sql : null;
        if ($sql === null) {
            throw new RuntimeException('nations table definition could not be read.');
        }

        $updated = preg_replace(
            '/("emblem_key"\s+varchar(?:\(\d+\))?\s+not null\s+default\s*)(?:\(\s*\'[^\']*\'\s*\)|\'[^\']*\')/i',
            '$1\''.$default.'\'',
            $sql,
            1,
            $replacementCount,
        );
        if (! is_string($updated) || $replacementCount !== 1) {
            throw new RuntimeException('emblem_key default could not be updated safely.');
        }

        $schemaVersion = (int) (DB::selectOne('PRAGMA schema_version')->schema_version ?? 0);
        DB::statement('PRAGMA writable_schema = ON');
        try {
            DB::update(
                "UPDATE sqlite_master SET sql = ? WHERE type = 'table' AND name = 'nations'",
                [$updated],
            );
        } finally {
            DB::statement('PRAGMA writable_schema = OFF');
        }
        DB::statement('PRAGMA schema_version = '.($schemaVersion + 1));

        $integrity = DB::selectOne('PRAGMA integrity_check');
        if (($integrity?->integrity_check ?? null) !== 'ok') {
            throw new RuntimeException('SQLite schema integrity check failed after updating emblem_key default.');
        }
    }
};
