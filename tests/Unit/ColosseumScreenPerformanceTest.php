<?php

namespace Tests\Unit;

use Tests\TestCase;

class ColosseumScreenPerformanceTest extends TestCase
{
    public function test_colosseum_screen_uses_scoped_ranking_entries_and_one_storage_summary(): void
    {
        $screenSource = file_get_contents(app_path('Livewire/ColosseumScreen.php'));
        $rankingSource = file_get_contents(app_path('Services/ArenaNpcRankingService.php'));

        $this->assertIsString($screenSource);
        $this->assertIsString($rankingSource);

        $this->assertStringContainsString('screenEntries($this->myRanking, 6, 3)', $screenSource);
        $this->assertStringNotContainsString('->topEntries(5)', $screenSource);
        $this->assertStringNotContainsString('->targetEntries($this->myRanking, 3)', $screenSource);
        $this->assertStringContainsString('$storageCapacityService->summary($this->character)', $screenSource);
        $this->assertStringContainsString('fullMessageHtml($this->character, $storageSummary)', $screenSource);
        $this->assertStringNotContainsString('闘技場の表示を「', $screenSource);

        $this->assertStringContainsString('public function screenEntries(', $rankingSource);
        $this->assertStringContainsString("->where('rank', '<=', \$topLimit)", $rankingSource);
        $this->assertStringContainsString("->where('rank', '<', (int) \$myRanking->rank)", $rankingSource);
        $this->assertStringContainsString('private bool $rankIntegrityChecked = false;', $rankingSource);
    }

    public function test_colosseum_top_six_uses_icon_led_podium_layout(): void
    {
        $viewSource = file_get_contents(resource_path('views/livewire/colosseum-screen.blade.php'));

        $this->assertIsString($viewSource);
        $this->assertStringContainsString('aria-label="闘技場上位6名"', $viewSource);
        $this->assertStringContainsString("1 => 'col-span-2 col-start-3 row-start-1'", $viewSource);
        $this->assertStringContainsString("2 => 'col-span-2 col-start-2 row-start-2'", $viewSource);
        $this->assertStringContainsString("3 => 'col-span-2 col-start-4 row-start-2'", $viewSource);
        $this->assertStringContainsString("6 => 'col-span-2 col-start-5 row-start-3'", $viewSource);
        $this->assertStringContainsString("'h-24 w-24 sm:h-28 sm:w-28'", $viewSource);
        $this->assertStringContainsString('class="mb-1 flex h-10 items-center justify-center" aria-label="{{ $rank }}位"', $viewSource);
        $this->assertStringNotContainsString('absolute -bottom-1 -left-1', $viewSource);
    }
}
