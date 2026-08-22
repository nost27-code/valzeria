<?php

namespace Tests\Unit;

use Tests\TestCase;

class CityHeaderExplorationStaminaHelpTest extends TestCase
{
    public function test_city_header_exposes_stamina_help_for_pointer_and_mobile_users(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $view = file_get_contents($projectRoot.'/resources/views/livewire/city-header.blade.php');
        $help = file_get_contents($projectRoot.'/resources/views/livewire/partials/exploration-stamina-help.blade.php');

        $this->assertIsString($view);
        $this->assertIsString($help);
        $this->assertStringContainsString('data-exploration-stamina-help-trigger', $view);
        $this->assertStringContainsString('data-exploration-stamina-help-popover', $view);
        $this->assertStringContainsString('data-exploration-stamina-help-sheet', $view);
        $this->assertStringContainsString('x-teleport="body"', $view);
        $this->assertStringContainsString('@mouseenter="if (pointerCanHover()) helpOpen = true"', $view);
        $this->assertStringContainsString('次の自然回復', $help);
        $this->assertStringContainsString('勝利数による上限', $help);
        $this->assertStringContainsString('アイテムで増えた探索力は上限を超えて保有できます', $help);
    }
}
