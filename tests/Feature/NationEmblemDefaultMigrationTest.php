<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class NationEmblemDefaultMigrationTest extends TestCase
{
    public function test_migration_normalizes_legacy_keys_and_default_without_losing_children(): void
    {
        $originalConnection = DB::getDefaultConnection();
        config()->set('database.connections.nation_emblem_probe', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('nation_emblem_probe');
        DB::setDefaultConnection('nation_emblem_probe');

        try {
            DB::statement('PRAGMA foreign_keys = ON');
            Schema::create('nations', function (Blueprint $table): void {
                $table->id();
                $table->string('emblem_key', 32)->default('green_castle');
            });
            Schema::create('nation_memberships', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('nation_id')->constrained('nations')->cascadeOnDelete();
            });
            DB::table('nations')->insert([
                ['id' => 1, 'emblem_key' => 'green_castle'],
                ['id' => 2, 'emblem_key' => 'blue_shield'],
            ]);
            DB::table('nation_memberships')->insert(['id' => 1, 'nation_id' => 1]);

            $migration = require database_path('migrations/2026_08_24_100000_align_nation_emblem_default.php');
            $migration->up();

            $this->assertSame('nation_crest_001', DB::table('nations')->where('id', 1)->value('emblem_key'));
            $this->assertSame('nation_crest_002', DB::table('nations')->where('id', 2)->value('emblem_key'));
            $this->assertSame(1, DB::table('nation_memberships')->count());
            $this->assertSame("'nation_crest_001'", $this->emblemDefault());
            DB::table('nations')->insert(['id' => 3]);
            $this->assertSame('nation_crest_001', DB::table('nations')->where('id', 3)->value('emblem_key'));
            $this->assertSame([], DB::select('PRAGMA foreign_key_check'));

            $migration->down();

            $this->assertSame(1, DB::table('nation_memberships')->count());
            $this->assertSame("'green_castle'", $this->emblemDefault());
            DB::table('nations')->insert(['id' => 4]);
            $this->assertSame('green_castle', DB::table('nations')->where('id', 4)->value('emblem_key'));
            $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        } finally {
            DB::disconnect('nation_emblem_probe');
            DB::purge('nation_emblem_probe');
            DB::setDefaultConnection($originalConnection);
        }
    }

    private function emblemDefault(): ?string
    {
        foreach (DB::select('PRAGMA table_info(nations)') as $column) {
            if ($column->name === 'emblem_key') {
                return $column->dflt_value;
            }
        }

        return null;
    }
}
