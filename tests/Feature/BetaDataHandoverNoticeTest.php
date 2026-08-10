<?php

namespace Tests\Feature;

use Tests\TestCase;

class BetaDataHandoverNoticeTest extends TestCase
{
    public function test_top_page_states_beta_data_handover_policy(): void
    {
        $html = view('welcome2', [
            'totalCharacters' => 0,
            'onlineCharacters' => collect(),
            'onlineCount' => 0,
            'registrationOpen' => true,
            'topPageVisit' => null,
            'champSummary' => [],
        ])->render();

        $this->assertSame(
            2,
            substr_count($html, 'β版での冒険データは、正式版へ引き継ぎます。')
        );
    }
}
