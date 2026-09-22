<?php

namespace Tests\Feature;

use App\Models\GameText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExplorationDangerHelpTest extends TestCase
{
    use RefreshDatabase;

    public function test_help_explains_the_scope_and_cap_of_danger_reward_bonuses(): void
    {
        $response = $this->get('/help');

        $response->assertOk();
        $response->assertSeeText('危険度が一定値に達すると、通常素材・通常装備のドロップ抽選が段階的に有利になります。');
        $response->assertSeeText('危険度100%以上では、この通常ドロップ補正は同じです。');
        $response->assertSeeText('装備のランクや品質は危険度では変化しません。');
        $response->assertDontSeeText('高い危険度のエリアほど良い報酬が期待できます。');
    }

    public function test_migration_updates_the_existing_help_override_without_losing_other_text(): void
    {
        GameText::query()->create([
            'key' => 'help.sections.exploration_metrics.body',
            'value' => '<p>上書き前文</p><dl><dd>エリアの難易度や敵の強さを示す指標です。危険度が高いほど敵が手強くなっていき、敗北のリスクも上がります。一方で、高い危険度のエリアほど良い報酬が期待できます。</dd></dl><p>上書き後文</p>',
            'description' => '危険度ヘルプの上書き',
        ]);

        $migration = require database_path('migrations/2026_09_23_010000_clarify_exploration_danger_help_text.php');
        $migration->up();
        $migration->up();

        $value = (string) GameText::query()
            ->where('key', 'help.sections.exploration_metrics.body')
            ->value('value');

        $this->assertStringContainsString('上書き前文', $value);
        $this->assertStringContainsString('上書き後文', $value);
        $this->assertStringContainsString('危険度100%以上では、この通常ドロップ補正は同じです。', $value);
        $this->assertSame(1, substr_count($value, '危険度100%以上では'));
        $this->assertStringNotContainsString('高い危険度のエリアほど良い報酬が期待できます。', $value);
    }
}
