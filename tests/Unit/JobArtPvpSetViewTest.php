<?php

namespace Tests\Unit;

use App\Services\JobArtService;
use Tests\TestCase;

class JobArtPvpSetViewTest extends TestCase
{
    public function test_ui_uses_flag_aware_context_slot_and_cost_limits(): void
    {
        $view = file_get_contents(resource_path('views/job-arts/index.blade.php'));
        $controller = file_get_contents(app_path('Http/Controllers/JobArtController.php'));

        $this->assertIsString($view);
        $this->assertIsString($controller);
        $this->assertStringContainsString("'pvp' => '対人'", $view);
        $this->assertStringContainsString('availableContexts: @js(array_keys($slotContextLabels))', $view);
        $this->assertStringContainsString("pvp: '対人'", $view);
        $this->assertStringContainsString('@for($slotNo = 1; $slotNo <= $maxSlots; $slotNo++)', $view);
        $this->assertStringContainsString('/{{ $maxCost }}</div>', $view);
        $this->assertStringContainsString('$jobArtService->slotContexts()', $controller);
        $this->assertStringContainsString('$jobArtService->availabilityContextForSlotContext($slotContext)', $controller);
        $this->assertSame(3, JobArtService::MAX_SLOTS);
        $this->assertSame(5, JobArtService::MAX_COST);
    }

    public function test_slot_ui_shows_effective_cost_and_inactive_reason_without_fixed_width(): void
    {
        $view = file_get_contents(resource_path('views/job-arts/index.blade.php'));
        $slotCard = file_get_contents(resource_path('views/job-arts/partials/slot-card.blade.php'));
        $mainScreen = file_get_contents(app_path('Livewire/MainScreen.php'));

        $this->assertIsString($view);
        $this->assertIsString($slotCard);
        $this->assertIsString($mainScreen);
        $this->assertStringContainsString("getAttribute('job_art_effective_cost')", $view);
        $this->assertStringContainsString("getAttribute('job_art_inactive_reason')", $slotCard);
        $this->assertStringContainsString("'slot_limit' => \$jobArtV2UiEnabled ? '5枠上限' : '枠数上限のため休止'", $slotCard);
        $this->assertStringContainsString("'cost_limit' => \$jobArtV2UiEnabled ? 'Cost上限超過' : 'Cost上限を超えたため休止'", $slotCard);
        $this->assertStringContainsString('data-job-art-inactive-reason', $slotCard);
        $this->assertStringContainsString('mx-auto w-full max-w-[560px]', $view);
        $this->assertStringNotContainsString('min-w-[', $slotCard);
        $this->assertStringContainsString('app(JobArtService::class)->maxSlots()', $mainScreen);
    }
}
