<?php

namespace Tests\Feature;

use App\Livewire\Admin\BugReportManager;
use App\Models\BugReport;
use App\Models\Character;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BugReportCodexCopyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_copy_a_bug_report_as_a_codex_investigation_request(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $reporter = Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => '調査対象冒険者',
            'explore_stamina' => 0,
        ]);
        $report = BugReport::query()->create([
            'character_id' => $reporter->id,
            'body' => '装備市場で取り消し後の表示がおかしいです。',
            'status' => 'new',
            'reported_url' => 'https://valzeria.com/market?private=value',
            'user_agent' => 'Android Chrome',
        ]);

        $this->actingAs($admin);

        Livewire::test(BugReportManager::class)
            ->call('selectReport', $report->id)
            ->assertSee('Codex調査用にコピー')
            ->assertSee('不具合調査依頼')
            ->assertSee('調査対象冒険者')
            ->assertSee('装備市場で取り消し後の表示がおかしいです。')
            ->assertSee('添付画像は必要なものを続けて貼り付けてください');
    }
}
