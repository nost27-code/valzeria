<?php

namespace Tests\Feature;

use App\Models\City;
use App\Services\NamelessPreparationService;
use App\Services\NamelessTownService;
use App\Services\NamelessWorkshopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NamelessPreparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_off_preparation_registers_only_one_hidden_town_and_is_repeatable(): void
    {
        config(['nameless_relics.enabled' => false]);
        $this->assertTrue(app(NamelessPreparationService::class)->status()['ready']);
        $this->artisan('nameless:prepare', ['--apply' => true, '--register-town' => true])->assertSuccessful();
        $id = City::query()->where('unlock_condition_type', NamelessTownService::MARKER)->value('id');
        $this->artisan('nameless:prepare', ['--apply' => true, '--register-town' => true])->assertSuccessful();
        $this->assertSame($id, City::query()->where('unlock_condition_type', NamelessTownService::MARKER)->sole()->id);
        $this->assertFalse(app(NamelessWorkshopService::class)->ready());
        $this->assertTrue(app(NamelessWorkshopService::class)->schemaReady());
        $this->assertNull(app(NamelessTownService::class)->availableTown());
    }

    public function test_default_command_is_read_only_and_reports_missing_town(): void
    {
        config(['nameless_relics.enabled' => false]);
        $before = City::query()->count();
        $this->artisan('nameless:prepare', ['--json' => true])->assertFailed();
        $this->assertSame($before, City::query()->count());
    }

    public function test_prepare_refuses_mutations_when_feature_is_on(): void
    {
        config(['nameless_relics.enabled' => true]);
        $before = City::query()->count();
        $this->artisan('nameless:prepare', ['--apply' => true, '--register-town' => true])->assertFailed();
        $this->assertSame($before, City::query()->count());
    }

    public function test_missing_socket_unique_blocks_off_registration(): void
    {
        config(['nameless_relics.enabled' => false]);
        Schema::table('player_relics', fn ($table) => $table->dropUnique('player_relic_ordinary_socket_unique'));
        $status = app(NamelessPreparationService::class)->status();
        $this->assertFalse($status['ready']);
        $this->assertContains('player_relics:unique:character_item_id,slot_number', $status['constraint_problems']);
        $this->artisan('nameless:prepare', ['--register-town' => true])->assertFailed();
        $this->assertSame(0, City::query()->where('unlock_condition_type', NamelessTownService::MARKER)->count());
    }
}
