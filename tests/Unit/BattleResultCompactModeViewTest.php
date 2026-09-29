<?php

namespace Tests\Unit;

use Tests\TestCase;

class BattleResultCompactModeViewTest extends TestCase
{
    public function test_compact_mode_keeps_exploration_actions_outside_collapsed_result_sections(): void
    {
        $view = (string) file_get_contents(resource_path('views/battle/result.blade.php'));

        $logAccordion = strpos($view, '<details open data-compact-accordion="log"');
        $resultAccordion = strpos($view, '<details open data-compact-accordion="result"');
        $resultAccordionClose = strpos($view, "</details>\n\n                    {{-- アクションボタン --}}", $resultAccordion ?: 0);
        $actionButtons = strpos($view, '{{-- アクションボタン --}}');

        $this->assertNotFalse($logAccordion);
        $this->assertNotFalse($resultAccordion);
        $this->assertNotFalse($resultAccordionClose);
        $this->assertNotFalse($actionButtons);
        $this->assertLessThan($resultAccordion, $logAccordion);
        $this->assertLessThan($resultAccordionClose, $resultAccordion);
        $this->assertLessThan($actionButtons, $resultAccordionClose);
        $this->assertStringContainsString('data-battle-full-only', $view);
        $this->assertStringContainsString('data-battle-compact-only', $view);
        $this->assertStringContainsString('data-battle-compact-title', $view);
        $this->assertStringContainsString('data-compact-player-hp-bar', $view);
        $this->assertStringContainsString('data-compact-player-sp-bar', $view);
        $this->assertStringContainsString("\$result['enemy_hp_after']", $view);
        $this->assertStringContainsString('data-battle-action-area', $view);
    }

    public function test_compact_mode_is_restored_after_async_exploration_result_replacement(): void
    {
        $view = (string) file_get_contents(resource_path('views/battle/result.blade.php'));

        $this->assertStringContainsString('valzeria:battle-result-compact:${characterId}', $view);
        $this->assertStringContainsString('window.__valzeriaInitBattleCompactMode(replacedPage || document);', $view);
        $this->assertStringContainsString('details.open = !enabled;', $view);
        $this->assertStringContainsString("document.body.classList.toggle('battle-result-compact', enabled);", $view);
        $this->assertStringContainsString('body.battle-result-compact [data-facility-header-wrapper] { display: none; }', $view);
    }
}
