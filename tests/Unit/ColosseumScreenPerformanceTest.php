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

        $this->assertStringContainsString('screenEntries($this->myRanking, 5, 3)', $screenSource);
        $this->assertStringNotContainsString('->topEntries(5)', $screenSource);
        $this->assertStringNotContainsString('->targetEntries($this->myRanking, 3)', $screenSource);
        $this->assertStringContainsString('$storageCapacityService->summary($this->character)', $screenSource);
        $this->assertStringContainsString('fullMessageHtml($this->character, $storageSummary)', $screenSource);

        $this->assertStringContainsString('public function screenEntries(', $rankingSource);
        $this->assertStringContainsString("->where('rank', '<=', \$topLimit)", $rankingSource);
        $this->assertStringContainsString("->where('rank', '<', (int) \$myRanking->rank)", $rankingSource);
        $this->assertStringContainsString('private bool $rankIntegrityChecked = false;', $rankingSource);
    }
}
