<?php

namespace Tests\Feature;

use App\Services\NamelessPreparationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NamelessPreparationMigrationTest extends TestCase
{
    public function test_only_five_migrations_apply_off_and_preserve_preexisting_weapon(): void
    {
        config(['nameless_relics.enabled' => false]);
        Schema::create('characters', fn ($table) => $table->id());
        Schema::create('character_items', fn ($table) => $table->id());
        DB::table('characters')->insert(['id' => 1]);
        Artisan::call('migrate:install');
        (require database_path('migrations/2026_07_10_100000_create_player_nameless_equipments.php'))->up();
        $id = DB::table('player_nameless_equipments')->insertGetId(['character_id' => 1, 'kind' => 'weapon',
            'custom_name' => '既存武器', 'equipment_type' => '剣', 'forge_level' => 17]);
        $before = (array) DB::table('player_nameless_equipments')->find($id);
        app(NamelessPreparationService::class)->applyMigrations();
        $this->assertSame(5, DB::table('migrations')->count());
        $this->assertTrue(app(NamelessPreparationService::class)->status()['ready']);
        $after = (array) DB::table('player_nameless_equipments')->find($id);
        $this->assertSame($before, array_intersect_key($after, $before));
        $this->assertFalse(app(NamelessPreparationService::class)->status()['enabled']);
        // Retry after a server stops between DDL and recording the migration.
        DB::table('migrations')->delete();
        app(NamelessPreparationService::class)->applyMigrations();
        $this->assertSame(5, DB::table('migrations')->count());
        $this->assertSame($after, (array) DB::table('player_nameless_equipments')->find($id));
        $this->assertFalse(Schema::hasTable('users'));
    }
}
