<?php

namespace Tests\Feature;

use App\Livewire\CityHeader;
use App\Livewire\MainScreen;
use App\Models\Character;
use App\Models\City;
use App\Services\FerdiaMapService;
use ReflectionMethod;
use Tests\TestCase;

class FerdiaSimpleBaseDisplayTest extends TestCase
{
    public function test_unvisited_ferdia_city_uses_the_simple_base_display(): void
    {
        app()->instance(FerdiaMapService::class, new class extends FerdiaMapService {
            public function isFerdiaCityId(int $cityId): bool
            {
                return in_array($cityId, [101, 102], true);
            }

            public function canTravelCity(Character $character, City $city): bool
            {
                return (int) $city->id === 102;
            }
        });

        $character = new Character();
        $unvisitedCity = new City();
        $unvisitedCity->id = 101;
        $visitedCity = new City();
        $visitedCity->id = 102;

        $mainScreen = new MainScreen();
        $mainScreen->character = $character;

        $this->assertTrue($this->invoke($mainScreen, 'isFerdiaSimpleBase', $unvisitedCity));
        $this->assertFalse($this->invoke($mainScreen, 'isFerdiaSimpleBase', $visitedCity));

        $cityHeader = new CityHeader();
        $this->assertTrue($this->invoke($cityHeader, 'shouldShowFerdiaSimpleBase', $character, $unvisitedCity));
        $this->assertFalse($this->invoke($cityHeader, 'shouldShowFerdiaSimpleBase', $character, $visitedCity));
    }

    public function test_current_undiscovered_ferdia_city_card_hides_its_name(): void
    {
        $character = new Character();
        $character->current_city_id = 101;

        $view = $this->view('components.ferdia-map', [
            'character' => $character,
            'map' => [
                'name' => 'フェルディア大陸',
                'subtitle' => '',
                'map_image' => 'images/map/map02.webp',
                'placeholder_image' => 'images/map/map02.webp',
                'image_exists' => true,
                'nodes' => [[
                    'key' => 'luvan',
                    'name' => '未発見',
                    'city_id' => 101,
                    'state' => 'unlocked',
                    'x_percent' => 50,
                    'y_percent' => 50,
                ]],
                'routes' => [],
            ],
        ]);

        $view->assertSee('未発見');
        $view->assertSee('まだ向かえません');
        $view->assertDontSee('現在地：未発見');
        $view->assertDontSee('辺境の町ルヴァン');
    }

    private function invoke(object $target, string $methodName, mixed ...$arguments): mixed
    {
        $method = new ReflectionMethod($target, $methodName);

        return $method->invoke($target, ...$arguments);
    }
}
