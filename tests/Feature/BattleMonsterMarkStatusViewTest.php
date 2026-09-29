<?php

namespace Tests\Feature;

use Tests\TestCase;

class BattleMonsterMarkStatusViewTest extends TestCase
{
    public function test_battle_result_mark_summary_distinguishes_current_lifetime_and_surplus_counts(): void
    {
        $html = view('battle.partials.monster-mark-status', [
            'summary' => [
                'total_types' => 2,
                'discovered_types' => 2,
                'current_total' => 32,
                'surplus_total' => 2,
                'alchemy' => [
                    'surplus_total' => 10,
                    'remaining_to_next' => 10,
                    'at_cap' => false,
                ],
                'entries' => [
                    [
                        'mark_name' => '呪い騎士の印',
                        'lifetime_quantity' => 35,
                        'spent_quantity' => 20,
                        'current_quantity' => 15,
                        'surplus_quantity' => 0,
                        'next_required' => null,
                        'is_complete' => true,
                    ],
                    [
                        'mark_name' => '石牙狼の印',
                        'lifetime_quantity' => 17,
                        'spent_quantity' => 0,
                        'current_quantity' => 17,
                        'surplus_quantity' => 2,
                        'next_required' => null,
                        'is_complete' => true,
                    ],
                ],
            ],
            'battleResult' => [
                'monster_mark_drop' => ['name' => '石牙狼の印'],
            ],
            'summaryAreaName' => '試練の森',
        ])->render();

        $this->assertStringContainsString('data-monster-mark-battle-summary', $html);
        $this->assertStringContainsString('試練の森', $html);
        $this->assertStringContainsString('エリアの印', $html);
        $this->assertStringContainsString('現在 32個', $html);
        $this->assertStringContainsString('錬成可能な余剰印・全体', $html);
        $this->assertStringContainsString('次の錬成まであと10個', $html);
        $this->assertStringContainsString('現在 15個', $html);
        $this->assertStringContainsString('累計発見 35個', $html);
        $this->assertStringContainsString('+1獲得', $html);
        $this->assertStringContainsString('余剰 2個', $html);
        $this->assertStringNotContainsString('印図鑑', $html);
        $this->assertStringNotContainsString('印錬成所', $html);
        $this->assertStringNotContainsString('divide-x', $html);
    }
}
