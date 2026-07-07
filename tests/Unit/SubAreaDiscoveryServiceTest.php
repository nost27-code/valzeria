<?php

namespace Tests\Unit;

use App\Models\Area;
use App\Models\Character;
use App\Models\CharacterSubAreaRouteDiscovery;
use App\Models\SubArea;
use App\Models\SubAreaRoute;
use App\Services\SubAreaDiscoveryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SubAreaDiscoveryServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'character_sub_area_route_discoveries',
            'sub_area_routes',
            'sub_areas',
            'areas',
            'characters',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('characters', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->unsignedInteger('highest_city_id')->default(1);
            $table->unsignedInteger('explore_stamina')->nullable();
            $table->unsignedInteger('explore_stamina_max')->nullable();
            $table->timestamp('explore_stamina_updated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('areas', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('city_id')->nullable();
            $table->timestamps();
        });

        Schema::create('sub_areas', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('sub_area_routes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('sub_area_id');
            $table->unsignedBigInteger('source_area_id');
            $table->string('route_name')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('character_sub_area_route_discoveries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('character_id');
            $table->unsignedBigInteger('sub_area_route_id');
            $table->timestamp('discovered_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        foreach ([
            'character_sub_area_route_discoveries',
            'sub_area_routes',
            'sub_areas',
            'areas',
            'characters',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_discovered_routes_can_be_filtered_by_source_area_city(): void
    {
        $character = Character::query()->create([
            'name' => 'Sub Area Tester',
            'highest_city_id' => 3,
            'explore_stamina' => 250,
            'explore_stamina_max' => 250,
            'explore_stamina_updated_at' => now(),
        ]);

        $elfiaArea = Area::query()->create(['name' => '世界樹の根', 'city_id' => 3]);
        $arkreaArea = Area::query()->create(['name' => '古王国の水道橋', 'city_id' => 1]);
        $subArea = SubArea::query()->create(['name' => '世界樹の水脈', 'is_enabled' => true]);

        $elfiaRoute = SubAreaRoute::query()->create([
            'sub_area_id' => $subArea->id,
            'source_area_id' => $elfiaArea->id,
            'route_name' => '世界樹の根の水音',
            'is_enabled' => true,
        ]);
        $arkreaRoute = SubAreaRoute::query()->create([
            'sub_area_id' => $subArea->id,
            'source_area_id' => $arkreaArea->id,
            'route_name' => '古王国の水脈図',
            'is_enabled' => true,
        ]);

        CharacterSubAreaRouteDiscovery::query()->create([
            'character_id' => $character->id,
            'sub_area_route_id' => $elfiaRoute->id,
            'discovered_at' => now()->subMinute(),
        ]);
        CharacterSubAreaRouteDiscovery::query()->create([
            'character_id' => $character->id,
            'sub_area_route_id' => $arkreaRoute->id,
            'discovered_at' => now(),
        ]);

        $discoveries = app(SubAreaDiscoveryService::class)->discoveredRoutes($character, 3);

        $this->assertCount(1, $discoveries);
        $this->assertSame($elfiaRoute->id, (int) $discoveries->first()->sub_area_route_id);
        $this->assertSame('世界樹の根', $discoveries->first()->route->sourceArea->name);
    }
}
