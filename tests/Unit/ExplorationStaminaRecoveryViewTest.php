<?php

namespace Tests\Unit;

use Tests\TestCase;

class ExplorationStaminaRecoveryViewTest extends TestCase
{
    public function test_repeat_exploration_stamina_shortage_keeps_recovery_modal_reachable(): void
    {
        $view = (string) file_get_contents(resource_path('views/battle/result.blade.php'));

        $this->assertStringContainsString('button.disabled = hpBlocked;', $view);
        $this->assertStringNotContainsString('button.disabled = current < required || hpBlocked;', $view);

        $shortageStart = strpos($view, "str_contains((string) \$result['error'], '探索力')");
        $shortageEnd = strpos($view, "@elseif(!isset(\$result['error'])", $shortageStart ?: 0);

        $this->assertNotFalse($shortageStart);
        $this->assertNotFalse($shortageEnd);

        $shortageBranch = substr($view, $shortageStart, $shortageEnd - $shortageStart);

        $this->assertStringContainsString('data-async-explore-form', $shortageBranch);
        $this->assertStringContainsString('data-required-stamina="{{ $selectedStaminaCost }}"', $shortageBranch);
        $this->assertStringContainsString('name="batch_count" value="{{ $selectedExploreCount }}"', $shortageBranch);
        $this->assertStringContainsString("route('battle.resume.return')", $shortageBranch);
    }

    public function test_sub_area_victory_uses_the_same_repeat_count_form_as_normal_exploration(): void
    {
        $view = (string) file_get_contents(resource_path('views/battle/result.blade.php'));

        $this->assertStringContainsString("route('battle.sub_area.explore', ['discovery' => \$result['sub_area_discovery_id']])", $view);
        $this->assertStringContainsString('form action="{{ $exploreAgainAction }}"', $view);
        $this->assertStringContainsString('data-explore-count-form', $view);
        $this->assertStringContainsString('name="batch_count" x-bind:value="effectiveCount"', $view);
        $this->assertStringNotContainsString("\$isVictoryResult && (\$result['special_event'] ?? null) === 'sub_area_explore'", $view);
    }
}
