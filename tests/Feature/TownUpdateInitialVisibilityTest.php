<?php

namespace Tests\Feature;

use App\Livewire\CityHeader;
use App\Models\TopUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TownUpdateInitialVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_town_updates_are_disabled_by_the_publication_fields_migration(): void
    {
        $this->assertGreaterThan(0, TopUpdate::query()->count());
        $this->assertSame(0, TopUpdate::query()->where('is_active', true)->count());
    }

    public function test_city_header_keeps_the_legacy_beta_message_when_no_update_is_public(): void
    {
        Livewire::test(CityHeader::class)
            ->assertSee('「ヴァルゼリアの冒険者」β版稼働中！')
            ->assertDontSee('[1/1]');
    }
}
