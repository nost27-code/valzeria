<?php

namespace Tests\Unit;

use Tests\TestCase;

class BattleTimeoutDefeatPresentationTest extends TestCase
{
    public function test_shared_battle_result_view_has_an_explicit_timeout_defeat_banner(): void
    {
        $view = (string) file_get_contents(resource_path('views/battle/result.blade.php'));

        $this->assertStringContainsString("\$isTimeoutDefeat = (\$result['result'] ?? null) === 'timeout'", $view);
        $this->assertStringContainsString("\$result['timeout_defeat_display'] ?? false", $view);
        $this->assertStringContainsString('data-timeout-defeat-banner', $view);
        $this->assertStringContainsString('時間切れ敗北', $view);
        $this->assertStringContainsString('敗北扱いとなりました', $view);
    }

    public function test_sub_area_result_carries_the_timeout_turn_count_to_the_shared_view(): void
    {
        $service = (string) file_get_contents(app_path('Services/SubAreaExplorationService.php'));

        $this->assertStringContainsString("'turn_count' => \$battleResult->turnCount,", $service);
    }
}
